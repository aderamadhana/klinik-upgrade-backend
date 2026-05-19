<?php

namespace App\Http\Controllers\Api\Registrasi;

use App\Http\Controllers\Controller;
use App\Models\Registrasi\RegistrasiDokterResepDetail;
use App\Models\Registrasi\RegistrasiDokterSoap;
use App\Models\Registrasi\RegistrasiKonsultasiFoto;
use App\Models\Registrasi\RegistrasiKonsultasiIntake;
use App\Models\Registrasi\RegistrasiKunjungan;
use App\Models\Registrasi\RegistrasiPenjualanDetail;
use App\Models\Registrasi\RegistrasiTask;
use App\Models\Registrasi\RegistrasiTreatmentDetail;
use App\Models\Pasien;
use App\Models\Master\MasterProdukToko;
use App\Models\Master\MasterTreatmentToko;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;

class RegistrasiLayananController extends Controller
{
    public function index(Request $request)
    {
        $query = RegistrasiKunjungan::query()
            ->with([
                'toko:id,nama_toko',
                'pasien:id,no_rm,nama,no_hp',
                'dokterAwal:id,nama',
                'perawatAwal:id,nama',
            ])
            ->active();

        if ($request->filled('toko_id')) {
            $query->where('toko_id', $request->toko_id);
        }

        if ($request->filled('tanggal')) {
            $query->whereDate('tanggal_kunjungan', $request->tanggal);
        }

        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }

        if ($request->filled('current_task')) {
            $query->where('current_task', $request->current_task);
        }

        if ($request->filled('search')) {
            $search = trim($request->search);

            $query->where(function ($q) use ($search) {
                $q->where('kode_registrasi', 'like', "%{$search}%")
                    ->orWhereHas('pasien', function ($p) use ($search) {
                        $p->where('nama', 'like', "%{$search}%")
                            ->orWhere('no_rm', 'like', "%{$search}%")
                            ->orWhere('no_hp', 'like', "%{$search}%");
                    });
            });
        }

        $rows = $query
            ->orderByDesc('registered_at')
            ->orderByDesc('id')
            ->paginate($request->get('per_page', 15));

        return response()->json([
            'status' => true,
            'message' => 'Data registrasi berhasil diambil',
            'data' => $rows,
        ]);
    }

    public function show($id)
    {
        $row = RegistrasiKunjungan::query()
            ->with([
                'toko',
                'pasien',
                'dokterAwal',
                'perawatAwal',
                'tasks.assignedKaryawan',
                'konsultasiIntake.fotos',
                'konsultasiFotos',
                'dokterSoap.resepDetails',
                'treatmentDetails.treatment',
                'treatmentDetails.treatmentToko',
                'penjualanDetails.produk',
                'penjualanDetails.produkToko',
                'perawatCppts',
                'beforeAfterFotos',
                'bahanTreatmentDetails',
            ])
            ->active()
            ->findOrFail($id);

        return response()->json([
            'status' => true,
            'message' => 'Detail registrasi berhasil diambil',
            'data' => $row,
        ]);
    }

    public function store(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'toko_id' => 'required|integer',
            'tanggal' => 'required|date',

            'pasien_id' => 'nullable|integer',
            'pasien_new_id' => 'nullable',

            'dokter_id' => 'nullable|integer',
            'perawat_id' => 'nullable|integer',

            'layanan' => 'required|array',
            'layanan.ada_konsultasi' => 'nullable|boolean',
            'layanan.channel_konsultasi' => 'nullable|string|in:offline,online',
            'layanan.ada_treatment' => 'nullable|boolean',
            'layanan.ada_penjualan' => 'nullable|boolean',

            'konsultasi_offline' => 'nullable|array',
            'konsultasi_offline.keluhan_awal' => 'nullable|string',
            'konsultasi_offline.catatan' => 'nullable|string',

            'konsultasi_online' => 'nullable|array',
            'konsultasi_online.request_dokter' => 'nullable',
            'konsultasi_online.alergi' => 'nullable|string',
            'konsultasi_online.keluhan' => 'nullable|string',
            'konsultasi_online.produk_sebelumnya' => 'nullable|string',
            'konsultasi_online.sedang_hamil' => 'nullable',
            'konsultasi_online.sedang_menyusui' => 'nullable',

            'treatment' => 'nullable|array',
            'treatment.items' => 'nullable|array',

            'penjualan' => 'nullable|array',
            'penjualan.items' => 'nullable|array',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status' => false,
                'message' => 'Validasi gagal',
                'errors' => $validator->errors(),
            ], 422);
        }

        $layanan = $request->input('layanan', []);

        $adaKonsultasi = $this->toBool($layanan['ada_konsultasi'] ?? false);
        $adaTreatment = $this->toBool($layanan['ada_treatment'] ?? false);
        $adaPenjualan = $this->toBool($layanan['ada_penjualan'] ?? false);

        if (!$adaKonsultasi && !$adaTreatment && !$adaPenjualan) {
            return response()->json([
                'status' => false,
                'message' => 'Minimal pilih satu layanan',
            ], 422);
        }

        if ($adaKonsultasi && empty($layanan['channel_konsultasi'])) {
            return response()->json([
                'status' => false,
                'message' => 'Channel konsultasi wajib dipilih',
            ], 422);
        }

        $pasienId = $this->resolvePasienId($request);

        if (!$pasienId) {
            return response()->json([
                'status' => false,
                'message' => 'Pasien tidak ditemukan',
            ], 422);
        }

        DB::beginTransaction();

        try {
            $treatmentItems = $this->normalizeTreatmentItems(
                $request->input('treatment.items', [])
            );

            $penjualanItems = $this->normalizePenjualanItems(
                $request->input('penjualan.items', [])
            );

            if ($adaTreatment && count($treatmentItems) === 0) {
                throw new \Exception('Minimal satu item treatment harus dipilih');
            }

            if ($adaPenjualan && count($penjualanItems) === 0) {
                throw new \Exception('Minimal satu produk harus dipilih');
            }

            $needNurseStation = collect($treatmentItems)->contains(function ($item) {
                return $this->toBool($item['perlu_tindakan_perawat'] ?? false)
                    || ($item['route_treatment'] ?? null) === 'nurse_station';
            });

            $totalTreatment = collect($treatmentItems)->sum('total');
            $totalPenjualan = collect($penjualanItems)->sum('subtotal');
            $grandTotal = $totalTreatment + $totalPenjualan;

            $channelKonsultasi = $this->mapChannelKonsultasi(
                $adaKonsultasi,
                $layanan['channel_konsultasi'] ?? null
            );

            $currentTask = $this->determineCurrentTask(
                $adaKonsultasi,
                $adaTreatment,
                $adaPenjualan,
                $needNurseStation
            );

            $registrasi = RegistrasiKunjungan::create([
                'kode_registrasi' => $this->generateKodeRegistrasi(
                    $request->toko_id,
                    $request->tanggal
                ),
                'toko_id' => $request->toko_id,
                'pasien_id' => $pasienId,
                'tanggal_kunjungan' => $request->tanggal,
                'registered_at' => now(),
                'catatan_registrasi' => $request->input('catatan_registrasi'),

                'dokter_awal_id' => $request->input('dokter_id'),
                'perawat_awal_id' => $request->input('perawat_id'),

                'channel_konsultasi' => $channelKonsultasi,
                'is_treatment' => $adaTreatment ? 1 : 0,
                'is_penjualan' => $adaPenjualan ? 1 : 0,
                'perlu_tindakan_perawat' => $needNurseStation ? 2 : ($adaTreatment ? 1 : 0),
                'current_task' => $currentTask,
                'status' => RegistrasiKunjungan::STATUS_AKTIF,

                'total_treatment' => $totalTreatment,
                'total_penjualan' => $totalPenjualan,
                'grand_total' => $grandTotal,

                'is_delete' => 0,
                'created_by' => $this->username(),
                'created_at' => now(),
            ]);

            $tasks = $this->createTasks(
                $registrasi,
                $adaKonsultasi,
                $adaTreatment,
                $adaPenjualan,
                $needNurseStation,
                $request
            );

            $konsultasi = null;

            if ($adaKonsultasi) {
                $konsultasi = $this->createKonsultasiIntake(
                    $registrasi,
                    $request,
                    $layanan['channel_konsultasi']
                );
            }

            if ($konsultasi && $layanan['channel_konsultasi'] === 'online') {
                $this->storeKonsultasiFotos($request, $registrasi, $konsultasi);
            }

            if ($adaTreatment) {
                $this->createTreatmentDetails($registrasi, $treatmentItems, $tasks);
            }

            if ($adaPenjualan) {
                $this->createPenjualanDetails($registrasi, $penjualanItems, $tasks);
            }

            DB::commit();

            $registrasi->load([
                'pasien',
                'dokterAwal',
                'perawatAwal',
                'tasks',
                'konsultasiIntake',
                'treatmentDetails',
                'penjualanDetails',
            ]);

            return response()->json([
                'status' => true,
                'message' => 'Registrasi berhasil disimpan',
                'data' => $registrasi,
            ], 201);
        } catch (\Throwable $e) {
            DB::rollBack();

            return response()->json([
                'status' => false,
                'message' => 'Gagal menyimpan registrasi',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    public function startTask($taskId)
    {
        $task = RegistrasiTask::findOrFail($taskId);

        if ((int) $task->status !== RegistrasiTask::STATUS_MENUNGGU) {
            return response()->json([
                'status' => false,
                'message' => 'Task tidak dalam status menunggu',
            ], 422);
        }

        DB::beginTransaction();

        try {
            $task->update([
                'status' => RegistrasiTask::STATUS_PROSES,
                'started_at' => now(),
                'updated_by' => $this->username(),
                'updated_at' => now(),
            ]);

            $task->registrasi()->update([
                'current_task' => $task->task_type,
                'updated_by' => $this->username(),
                'updated_at' => now(),
            ]);

            DB::commit();

            return response()->json([
                'status' => true,
                'message' => 'Task berhasil diproses',
                'data' => $task->fresh('registrasi'),
            ]);
        } catch (\Throwable $e) {
            DB::rollBack();

            return response()->json([
                'status' => false,
                'message' => 'Gagal memproses task',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    public function finishTask($taskId)
    {
        $task = RegistrasiTask::with('registrasi.tasks')->findOrFail($taskId);

        if (!in_array((int) $task->status, [
            RegistrasiTask::STATUS_MENUNGGU,
            RegistrasiTask::STATUS_PROSES,
        ], true)) {
            return response()->json([
                'status' => false,
                'message' => 'Task tidak bisa diselesaikan',
            ], 422);
        }

        DB::beginTransaction();

        try {
            $task->update([
                'status' => RegistrasiTask::STATUS_SELESAI,
                'finished_at' => now(),
                'updated_by' => $this->username(),
                'updated_at' => now(),
            ]);

            $registrasi = $task->registrasi;

            $nextTask = $registrasi->tasks()
                ->where('status', RegistrasiTask::STATUS_MENUNGGU)
                ->orderBy('task_order')
                ->first();

            if ($nextTask) {
                $registrasi->update([
                    'current_task' => $nextTask->task_type,
                    'updated_by' => $this->username(),
                    'updated_at' => now(),
                ]);
            } else {
                $registrasi->update([
                    'current_task' => 0,
                    'status' => RegistrasiKunjungan::STATUS_SELESAI,
                    'updated_by' => $this->username(),
                    'updated_at' => now(),
                ]);
            }

            DB::commit();

            return response()->json([
                'status' => true,
                'message' => 'Task berhasil diselesaikan',
                'data' => $registrasi->fresh(['tasks']),
            ]);
        } catch (\Throwable $e) {
            DB::rollBack();

            return response()->json([
                'status' => false,
                'message' => 'Gagal menyelesaikan task',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    public function cancel($id)
    {
        $registrasi = RegistrasiKunjungan::active()->findOrFail($id);

        DB::beginTransaction();

        try {
            $registrasi->update([
                'status' => RegistrasiKunjungan::STATUS_BATAL,
                'current_task' => 0,
                'updated_by' => $this->username(),
                'updated_at' => now(),
            ]);

            $registrasi->tasks()->update([
                'status' => RegistrasiTask::STATUS_BATAL,
                'updated_by' => $this->username(),
                'updated_at' => now(),
            ]);

            DB::commit();

            return response()->json([
                'status' => true,
                'message' => 'Registrasi berhasil dibatalkan',
            ]);
        } catch (\Throwable $e) {
            DB::rollBack();

            return response()->json([
                'status' => false,
                'message' => 'Gagal membatalkan registrasi',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    private function resolvePasienId(Request $request)
    {
        if ($request->filled('pasien_id')) {
            return Pasien::query()
                ->where('id', $request->pasien_id)
                ->where(function ($q) {
                    $q->where('is_delete', 0)->orWhereNull('is_delete');
                })
                ->value('id');
        }

        if (!$request->filled('pasien_new_id')) {
            return null;
        }

        $model = new Pasien();
        $table = $model->getTable();
        $value = $request->pasien_new_id;

        $query = Pasien::query()
            ->where(function ($q) {
                $q->where('is_delete', 0)->orWhereNull('is_delete');
            });

        $query->where(function ($q) use ($table, $value) {
            if (Schema::hasColumn($table, 'new_id')) {
                $q->orWhere('new_id', $value);
            }

            if (Schema::hasColumn($table, 'pasien_new_id')) {
                $q->orWhere('pasien_new_id', $value);
            }

            if (Schema::hasColumn($table, 'no_rm')) {
                $q->orWhere('no_rm', $value);
            }

            $q->orWhere('id', $value);
        });

        return $query->value('id');
    }

    private function createTasks(
        RegistrasiKunjungan $registrasi,
        bool $adaKonsultasi,
        bool $adaTreatment,
        bool $adaPenjualan,
        bool $needNurseStation,
        Request $request
    ) {
        $tasks = [];
        $order = 1;

        if ($adaKonsultasi) {
            $tasks['konsultasi'] = RegistrasiTask::create([
                'registrasi_id' => $registrasi->id,
                'task_type' => RegistrasiTask::TYPE_KONSULTASI,
                'assigned_karyawan_id' => $request->input('dokter_id'),
                'task_order' => $order++,
                'status' => RegistrasiTask::STATUS_MENUNGGU,
                'created_by' => $this->username(),
                'created_at' => now(),
            ]);
        }

        if ($adaTreatment) {
            $taskType = $needNurseStation
                ? RegistrasiTask::TYPE_TINDAKAN_PERAWAT
                : RegistrasiTask::TYPE_TREATMENT;

            $assignedKaryawanId = $needNurseStation
                ? $request->input('perawat_id')
                : $request->input('dokter_id');

            $tasks['treatment'] = RegistrasiTask::create([
                'registrasi_id' => $registrasi->id,
                'task_type' => $taskType,
                'assigned_karyawan_id' => $assignedKaryawanId,
                'task_order' => $order++,
                'status' => RegistrasiTask::STATUS_MENUNGGU,
                'created_by' => $this->username(),
                'created_at' => now(),
            ]);
        }

        if ($adaPenjualan) {
            $tasks['penjualan'] = RegistrasiTask::create([
                'registrasi_id' => $registrasi->id,
                'task_type' => RegistrasiTask::TYPE_PEMBAYARAN,
                'assigned_karyawan_id' => null,
                'task_order' => $order++,
                'status' => RegistrasiTask::STATUS_MENUNGGU,
                'created_by' => $this->username(),
                'created_at' => now(),
            ]);
        }

        return $tasks;
    }

    private function createKonsultasiIntake(
        RegistrasiKunjungan $registrasi,
        Request $request,
        string $channel
    ) {
        $payload = [
            'registrasi_id' => $registrasi->id,
            'jenis_konsultasi' => $channel === 'online'
                ? RegistrasiKonsultasiIntake::JENIS_ONLINE
                : RegistrasiKonsultasiIntake::JENIS_OFFLINE,
            'status' => RegistrasiKonsultasiIntake::STATUS_MENUNGGU,
            'created_by' => $this->username(),
            'created_at' => now(),
        ];

        if ($channel === 'offline') {
            $offline = $request->input('konsultasi_offline', []);

            $payload['keluhan_awal'] = $offline['keluhan_awal'] ?? null;
            $payload['catatan_awal'] = $offline['catatan'] ?? null;
        }

        if ($channel === 'online') {
            $online = $request->input('konsultasi_online', []);

            $payload['alergi'] = $online['alergi'] ?? null;
            $payload['keluhan_utama'] = $online['keluhan'] ?? null;
            $payload['produk_obat_sebelumnya'] = $online['produk_sebelumnya'] ?? null;
            $payload['sedang_hamil'] = $this->yesNoToTinyint($online['sedang_hamil'] ?? null);
            $payload['sedang_menyusui'] = $this->yesNoToTinyint($online['sedang_menyusui'] ?? null);

            $requestDokter = $online['request_dokter'] ?? null;

            if (is_numeric($requestDokter)) {
                $payload['request_dokter_id'] = $requestDokter;
            } elseif ($requestDokter && Schema::hasColumn('registrasi_konsultasi_intake', 'request_dokter_nama')) {
                $payload['request_dokter_nama'] = $requestDokter;
            }
        }

        return RegistrasiKonsultasiIntake::create($payload);
    }

    private function storeKonsultasiFotos(
        Request $request,
        RegistrasiKunjungan $registrasi,
        RegistrasiKonsultasiIntake $konsultasi
    ) {
        $files = [
            [
                'keys' => [
                    'konsultasi_online.bukti_foto_kiri',
                    'bukti_foto_kiri',
                    'foto_kiri',
                ],
                'posisi' => RegistrasiKonsultasiFoto::POSISI_KIRI,
            ],
            [
                'keys' => [
                    'konsultasi_online.bukti_foto_depan',
                    'bukti_foto_depan',
                    'foto_depan',
                ],
                'posisi' => RegistrasiKonsultasiFoto::POSISI_DEPAN,
            ],
            [
                'keys' => [
                    'konsultasi_online.bukti_foto_kanan',
                    'bukti_foto_kanan',
                    'foto_kanan',
                ],
                'posisi' => RegistrasiKonsultasiFoto::POSISI_KANAN,
            ],
        ];

        foreach ($files as $item) {
            $file = null;

            foreach ($item['keys'] as $key) {
                if ($request->hasFile($key)) {
                    $file = $request->file($key);
                    break;
                }
            }

            if (!$file) {
                continue;
            }

            $path = $file->store(
                "registrasi/konsultasi/{$registrasi->id}",
                'public'
            );

            RegistrasiKonsultasiFoto::create([
                'registrasi_id' => $registrasi->id,
                'konsultasi_id' => $konsultasi->id,
                'posisi_foto' => $item['posisi'],
                'file_name' => $file->getClientOriginalName(),
                'file_path' => $path,
                'file_url' => Storage::disk('public')->url($path),
                'mime_type' => $file->getMimeType(),
                'file_size' => $file->getSize(),
                'is_delete' => 0,
                'created_by' => $this->username(),
                'created_at' => now(),
            ]);
        }
    }

    private function createTreatmentDetails(
        RegistrasiKunjungan $registrasi,
        array $items,
        array $tasks
    ) {
        $task = $tasks['treatment'] ?? null;

        foreach ($items as $item) {
            $ids = $this->resolveTreatmentIds($item, $registrasi->toko_id);

            if (empty($ids['treatment_toko_id']) || empty($ids['treatment_id'])) {
                throw new \Exception(
                    'Mapping treatment toko tidak ditemukan untuk treatment_id: ' .
                    ($item['treatment_id'] ?? $item['tindakan_id'] ?? '-')
                );
            }

            RegistrasiTreatmentDetail::create([
                'registrasi_id' => $registrasi->id,
                'source_type' => RegistrasiTreatmentDetail::SOURCE_FO,
                'source_task_id' => $task?->id,
                'source_karyawan_id' => null,

                'is_deposit_claim' => $this->toBool($item['is_deposit_claim'] ?? false) ? 1 : 0,
                'deposit_treatment_id' => $item['deposit_treatment_id'] ?? null,
                'deposit_claim_id' => $item['deposit_claim_id'] ?? null,

                'treatment_toko_id' => $ids['treatment_toko_id'],
                'treatment_id' => $ids['treatment_id'],
                'nama_treatment' => $item['nama_treatment'] ?? $item['treatment_nama'] ?? $item['nama_tindakan'] ?? null,

                'harga' => $item['harga'],
                'jumlah' => $item['jumlah'],
                'total' => $item['total'],
                'catatan' => $item['catatan'] ?? null,

                'status' => RegistrasiTreatmentDetail::STATUS_BELUM_DIKERJAKAN,
                'is_delete' => 0,
                'created_by' => $this->username(),
                'created_at' => now(),
            ]);
        }
    }

    private function createPenjualanDetails(
        RegistrasiKunjungan $registrasi,
        array $items,
        array $tasks
    ) {
        $task = $tasks['penjualan'] ?? null;

        foreach ($items as $item) {
            $ids = $this->resolveProdukIds($item, $registrasi->toko_id);

            if (empty($ids['produk_toko_id']) || empty($ids['produk_id'])) {
                throw new \Exception(
                    'Mapping produk toko tidak ditemukan untuk produk_id: ' .
                    ($item['produk_id'] ?? $item['obat_id'] ?? '-')
                );
            }

            RegistrasiPenjualanDetail::create([
                'registrasi_id' => $registrasi->id,
                'source_type' => RegistrasiPenjualanDetail::SOURCE_FO,
                'source_task_id' => $task?->id,
                'source_resep_id' => $item['source_resep_id'] ?? null,
                'source_karyawan_id' => null,

                'produk_toko_id' => $ids['produk_toko_id'],
                'produk_id' => $ids['produk_id'],
                'nama_produk' => $item['nama_produk'] ?? $item['produk_nama'] ?? null,

                'harga' => $item['harga'],
                'jumlah' => $item['jumlah'],
                'diskon_tipe' => $item['diskon_tipe'] ?? $item['diskon_type'] ?? 0,
                'diskon_nilai' => $item['diskon_nilai'] ?? $item['diskon_value'] ?? 0,
                'diskon_referral' => $item['diskon_referral'] ?? 0,
                'subtotal' => $item['subtotal'],

                'is_delete' => 0,
                'created_by' => $this->username(),
                'created_at' => now(),
            ]);
        }
    }

    private function normalizeTreatmentItems(array $items)
    {
        $result = [];

        foreach ($items as $item) {
            $treatmentTokoId =
                $item['treatment_toko_id'] ??
                $item['master_treatment_toko_id'] ??
                $item['tindakan_toko_id'] ??
                $item['toko_treatment_id'] ??
                null;

            $treatmentId =
                $item['treatment_id'] ??
                $item['tindakan_id'] ??
                $item['master_treatment_id'] ??
                $item['id'] ??
                null;

            if (!$treatmentId && !$treatmentTokoId) {
                continue;
            }

            $harga = $this->toNumber($item['harga'] ?? 0);
            $jumlah = $this->toNumber($item['jumlah'] ?? 1);

            if ($jumlah <= 0) {
                $jumlah = 1;
            }

            $total = $this->toNumber($item['total'] ?? ($harga * $jumlah));

            $result[] = [
                ...$item,
                'treatment_toko_id' => $treatmentTokoId,
                'treatment_id' => $treatmentId,
                'harga' => $harga,
                'jumlah' => $jumlah,
                'total' => $total,
            ];
        }

        return $result;
    }

    private function normalizePenjualanItems(array $items)
    {
        $result = [];

        foreach ($items as $item) {
            $produkId = $item['produk_id']
                ?? $item['obat_id']
                ?? $item['id']
                ?? null;

            $produkTokoId = $item['produk_toko_id'] ?? null;

            if (!$produkId && !$produkTokoId) {
                continue;
            }

            $harga = $this->toNumber($item['harga'] ?? 0);
            $jumlah = $this->toNumber($item['jumlah'] ?? 1);

            if ($jumlah <= 0) {
                $jumlah = 1;
            }

            $subtotal = $this->toNumber($item['subtotal'] ?? ($harga * $jumlah));

            $result[] = [
                ...$item,
                'produk_id' => $produkId,
                'produk_toko_id' => $produkTokoId,
                'harga' => $harga,
                'jumlah' => $jumlah,
                'subtotal' => $subtotal,
            ];
        }

        return $result;
    }

    private function resolveTreatmentIds(array $item, $tokoId = null)
    {
        $treatmentTokoId =
            $item['treatment_toko_id'] ??
            $item['master_treatment_toko_id'] ??
            $item['tindakan_toko_id'] ??
            $item['toko_treatment_id'] ??
            null;

        $treatmentId =
            $item['treatment_id'] ??
            $item['tindakan_id'] ??
            $item['master_treatment_id'] ??
            null;

        $table = (new MasterTreatmentToko())->getTable();

        $row = null;

        if ($treatmentTokoId) {
            $row = MasterTreatmentToko::query()->find($treatmentTokoId);
        }

        if (!$row && $tokoId && $treatmentId) {
            $query = MasterTreatmentToko::query();

            if (Schema::hasColumn($table, 'toko_id')) {
                $query->where('toko_id', $tokoId);
            }

            $query->where(function ($q) use ($table, $treatmentId) {
                if (Schema::hasColumn($table, 'treatment_id')) {
                    $q->orWhere('treatment_id', $treatmentId);
                }

                if (Schema::hasColumn($table, 'master_treatment_id')) {
                    $q->orWhere('master_treatment_id', $treatmentId);
                }
            });

            $row = $query->first();
        }

        return [
            'treatment_toko_id' => $row?->id ?? $treatmentTokoId,
            'treatment_id' =>
                $row?->treatment_id ??
                $row?->master_treatment_id ??
                $treatmentId,
        ];
    }

    private function resolveProdukIds(array $item, $tokoId = null)
    {
        $produkTokoId =
            $item['produk_toko_id'] ??
            $item['master_produk_toko_id'] ??
            $item['obat_toko_id'] ??
            $item['toko_produk_id'] ??
            null;

        $produkId =
            $item['produk_id'] ??
            $item['obat_id'] ??
            $item['master_produk_id'] ??
            null;

        $table = (new MasterProdukToko())->getTable();

        $row = null;

        if ($produkTokoId) {
            $row = MasterProdukToko::query()->find($produkTokoId);
        }

        if (!$row && $tokoId && $produkId) {
            $query = MasterProdukToko::query();

            if (Schema::hasColumn($table, 'toko_id')) {
                $query->where('toko_id', $tokoId);
            }

            $query->where(function ($q) use ($table, $produkId) {
                if (Schema::hasColumn($table, 'produk_id')) {
                    $q->orWhere('produk_id', $produkId);
                }

                if (Schema::hasColumn($table, 'master_produk_id')) {
                    $q->orWhere('master_produk_id', $produkId);
                }

                if (Schema::hasColumn($table, 'obat_id')) {
                    $q->orWhere('obat_id', $produkId);
                }
            });

            if (Schema::hasColumn($table, 'is_delete')) {
                $query->where(function ($q) {
                    $q->where('is_delete', 0)->orWhereNull('is_delete');
                });
            }

            $row = $query->first();
        }

        return [
            'produk_toko_id' => $row?->id ?? $produkTokoId,
            'produk_id' =>
                $row?->produk_id ??
                $row?->master_produk_id ??
                $row?->obat_id ??
                $produkId,
        ];
    }

    private function determineCurrentTask(
        bool $adaKonsultasi,
        bool $adaTreatment,
        bool $adaPenjualan,
        bool $needNurseStation
    ) {
        if ($adaKonsultasi) {
            return RegistrasiKunjungan::TASK_KONSULTASI;
        }

        if ($adaTreatment) {
            return $needNurseStation
                ? RegistrasiKunjungan::TASK_PERAWAT
                : RegistrasiKunjungan::TASK_TREATMENT;
        }

        if ($adaPenjualan) {
            return RegistrasiKunjungan::TASK_PEMBAYARAN;
        }

        return RegistrasiKunjungan::TASK_DRAFT;
    }

    private function mapChannelKonsultasi(bool $adaKonsultasi, ?string $channel)
    {
        if (!$adaKonsultasi) {
            return RegistrasiKunjungan::CHANNEL_TIDAK_KONSULTASI;
        }

        return $channel === 'online'
            ? RegistrasiKunjungan::CHANNEL_ONLINE
            : RegistrasiKunjungan::CHANNEL_OFFLINE;
    }

    private function generateKodeRegistrasi($tokoId, $tanggal)
    {
        $date = Carbon::parse($tanggal)->format('Ymd');

        $count = RegistrasiKunjungan::query()
            ->where('toko_id', $tokoId)
            ->whereDate('tanggal_kunjungan', $tanggal)
            ->count() + 1;

        return 'REG-' . $date . '-' . str_pad($tokoId, 2, '0', STR_PAD_LEFT) . '-' . str_pad($count, 4, '0', STR_PAD_LEFT);
    }

    private function yesNoToTinyint($value)
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (is_bool($value)) {
            return $value ? 1 : 0;
        }

        $value = strtolower(trim((string) $value));

        if (in_array($value, ['ya', 'yes', 'y', '1', 'true'], true)) {
            return 1;
        }

        if (in_array($value, ['tidak', 'no', 'n', '0', 'false'], true)) {
            return 0;
        }

        return null;
    }

    private function toBool($value)
    {
        if (is_bool($value)) {
            return $value;
        }

        if (is_numeric($value)) {
            return (int) $value === 1;
        }

        return in_array(strtolower((string) $value), ['1', 'true', 'yes', 'ya'], true);
    }

    private function toNumber($value)
    {
        if (is_numeric($value)) {
            return (float) $value;
        }

        if ($value === null || $value === '') {
            return 0;
        }

        return (float) preg_replace('/[^0-9.-]/', '', (string) $value);
    }

    private function username()
    {
        return auth()->user()->username
            ?? auth()->user()->name
            ?? 'system';
    }
}