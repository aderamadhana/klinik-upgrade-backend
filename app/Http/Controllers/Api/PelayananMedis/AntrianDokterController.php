<?php

namespace App\Http\Controllers\Api\PelayananMedis;

use App\Http\Controllers\Controller;
use App\Models\Registrasi\RegistrasiKunjungan;
use App\Models\Registrasi\RegistrasiTask;
use App\Models\Pembayaran\PembayaranInvoice;
use App\Models\Pembayaran\PembayaranInvoiceItem;
use App\Models\Registrasi\RegistrasiDokterResepDetail;
use App\Models\Registrasi\RegistrasiDokterSoap;
use App\Models\Registrasi\RegistrasiDokterSoapDiagnosa;
use App\Models\Registrasi\RegistrasiDokterSoapSubjective;
use App\Models\Registrasi\RegistrasiPenjualanDetail;
use App\Models\Registrasi\RegistrasiTreatmentDetail;
use Illuminate\Support\Facades\Validator;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class AntrianDokterController extends Controller
{
    public function index(Request $request)
    {
        $query = RegistrasiKunjungan::query()
            ->with([
                'toko',
                'pasien',
                'dokterAwal',
                'perawatAwal',
                'tasks' => function ($q) {
                    $q->orderBy('task_order');
                },
                'tasks.assignedKaryawan',
            ])
            ->active()
            ->where('status', RegistrasiKunjungan::STATUS_AKTIF)
            ->where('current_task', RegistrasiKunjungan::TASK_KONSULTASI)
            ->where(function ($q) {
                $q->whereIn('channel_konsultasi', [
                    RegistrasiKunjungan::CHANNEL_OFFLINE,
                    RegistrasiKunjungan::CHANNEL_ONLINE,
                ])
                ->orWhere('is_treatment', 1);
            });

        if ($request->filled('toko_id')) {
            $query->where('toko_id', $request->toko_id);
        }

        if ($request->filled('tanggal')) {
            $query->whereDate('tanggal_kunjungan', $request->tanggal);
        }

        if ($request->filled('channel')) {
            $this->applyChannelFilter($query, $request->channel);
        }

        if ($request->filled('status')) {
            $taskStatus = $this->mapQueueStatusToTaskStatus($request->status);

            if ($taskStatus !== null) {
                $query->whereHas('tasks', function ($q) use ($taskStatus) {
                    $q->where('task_type', RegistrasiTask::TYPE_KONSULTASI)
                        ->where('status', $taskStatus);
                });
            }
        }

        if ($request->filled('dokter_id')) {
            $query->where('dokter_awal_id', $request->dokter_id);
        }

        if ($request->filled('search')) {
            $search = trim($request->search);

            $query->where(function ($q) use ($search) {
                $q->where('kode_registrasi', 'like', "%{$search}%")
                    ->orWhereHas('pasien', function ($p) use ($search) {
                        $p->where('nama', 'like', "%{$search}%")
                            ->orWhere('no_rm', 'like', "%{$search}%")
                            ->orWhere('no_hp', 'like', "%{$search}%");
                    })
                    ->orWhereHas('dokterAwal', function ($d) use ($search) {
                        $d->where('nama', 'like', "%{$search}%");
                    });
            });
        }

        $rows = $query
            ->orderBy('registered_at')
            ->orderBy('id')
            ->paginate((int) $request->get('per_page', 15));

        $rows->getCollection()->transform(function ($row) {
            return $this->formatQueueRow($row);
        });

        return response()->json([
            'status' => true,
            'message' => 'Data antrian dokter berhasil diambil',
            'data' => $rows,
        ]);
    }

    public function show($id)
    {
        $registrasi = RegistrasiKunjungan::query()
            ->with([
                'toko',
                'pasien',
                'dokterAwal',
                'perawatAwal',
                'tasks' => function ($q) {
                    $q->orderBy('task_order');
                },
                'tasks.assignedKaryawan',
                'konsultasiIntake.fotos',
                'konsultasiFotos',
                'treatmentDetails.treatment',
                'treatmentDetails.treatmentToko',
                'penjualanDetails.produk',
                'penjualanDetails.produkToko',
            ])
            ->active()
            ->findOrFail($id);

        if (!$this->isDoctorQueue($registrasi)) {
            return response()->json([
                'status' => false,
                'message' => 'Registrasi ini tidak berada pada antrian dokter',
            ], 422);
        }

        return response()->json([
            'status' => true,
            'message' => 'Detail antrian dokter berhasil diambil',
            'data' => $this->formatQueueRow($registrasi),
        ]);
    }

    public function start($id)
    {
        $registrasi = RegistrasiKunjungan::query()
            ->with('tasks')
            ->active()
            ->findOrFail($id);

        if (!$this->isDoctorQueue($registrasi)) {
            return response()->json([
                'status' => false,
                'message' => 'Registrasi ini tidak berada pada antrian dokter',
            ], 422);
        }

        if ((int) $registrasi->status !== RegistrasiKunjungan::STATUS_AKTIF) {
            return response()->json([
                'status' => false,
                'message' => 'Registrasi tidak aktif',
            ], 422);
        }

        $task = $this->getDoctorTask($registrasi);

        if (!$task) {
            return response()->json([
                'status' => false,
                'message' => 'Task dokter tidak ditemukan',
            ], 422);
        }

        if ((int) $task->status === RegistrasiTask::STATUS_PROSES) {
            return response()->json([
                'status' => true,
                'message' => 'Antrian dokter sudah dalam proses',
                'data' => $this->formatQueueRow($registrasi->fresh([
                    'toko',
                    'pasien',
                    'dokterAwal',
                    'perawatAwal',
                    'tasks',
                ])),
            ]);
        }

        if ((int) $task->status !== RegistrasiTask::STATUS_MENUNGGU) {
            return response()->json([
                'status' => false,
                'message' => 'Antrian tidak bisa diproses karena status task tidak menunggu',
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

            $registrasi->update([
                'current_task' => RegistrasiKunjungan::TASK_KONSULTASI,
                'updated_by' => $this->username(),
                'updated_at' => now(),
            ]);

            DB::commit();

            $registrasi = $registrasi->fresh([
                'toko',
                'pasien',
                'dokterAwal',
                'perawatAwal',
                'tasks',
            ]);

            return response()->json([
                'status' => true,
                'message' => 'Antrian dokter berhasil diproses',
                'data' => $this->formatQueueRow($registrasi),
            ]);
        } catch (\Throwable $e) {
            DB::rollBack();

            return response()->json([
                'status' => false,
                'message' => 'Gagal memproses antrian dokter',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    public function finish(Request $request, $id)
    {
        $validator = Validator::make($request->all(), [
            'add_consultation' => 'nullable|boolean',
            'biaya_konsultasi' => 'nullable|numeric|min:0',

            'soap' => 'nullable|array',
            'soap.subjective' => 'nullable|string',
            'soap.subjective_items' => 'nullable|array',
            'soap.subjective_items.*' => 'nullable|string|max:255',
            'soap.subjective_other' => 'nullable|string',
            'soap.objective' => 'nullable|string',
            'soap.assessment' => 'nullable|string',
            'soap.assessment_items' => 'nullable|array',
            'soap.assessment_items.*' => 'nullable|string|max:255',
            'soap.assessment_other' => 'nullable|string',
            'soap.planning' => 'nullable|string',
            'soap.plan' => 'nullable|string',
            'soap.next_date_konsultasi' => 'nullable|date',

            'obat_items' => 'nullable|array',
            'obat_items.*.produk_toko_id' => 'nullable|integer',
            'obat_items.*.produk_id' => 'nullable|integer',
            'obat_items.*.nama' => 'nullable|string|max:150',
            'obat_items.*.nama_produk' => 'nullable|string|max:150',
            'obat_items.*.harga' => 'nullable|numeric|min:0',
            'obat_items.*.jumlah' => 'nullable|integer|min:1',
            'obat_items.*.qty' => 'nullable|integer|min:1',
            'obat_items.*.subtotal' => 'nullable|numeric|min:0',
            'obat_items.*.aturan_pakai' => 'nullable|string',
            'obat_items.*.frekuensi' => 'nullable|string|max:100',
            'obat_items.*.waktu_pakai' => 'nullable|string|max:100',

            'treatment_items' => 'nullable|array',
            'treatment_items.*.treatment_toko_id' => 'nullable|integer',
            'treatment_items.*.treatment_id' => 'nullable|integer',
            'treatment_items.*.nama' => 'nullable|string|max:255',
            'treatment_items.*.nama_treatment' => 'nullable|string|max:255',
            'treatment_items.*.harga' => 'nullable|numeric|min:0',
            'treatment_items.*.jumlah' => 'nullable|integer|min:1',
            'treatment_items.*.qty' => 'nullable|integer|min:1',
            'treatment_items.*.total' => 'nullable|numeric|min:0',
            'treatment_items.*.perawat_id' => 'nullable|integer',
            'treatment_items.*.perlu_tindakan_perawat' => 'nullable|boolean',
            'treatment_items.*.route_treatment' => 'nullable|string|max:30',
            'treatment_items.*.catatan' => 'nullable|string',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status' => false,
                'message' => 'Validasi gagal',
                'errors' => $validator->errors(),
            ], 422);
        }

        $registrasi = RegistrasiKunjungan::query()
            ->with('tasks')
            ->active()
            ->findOrFail($id);

        if (!$this->isDoctorQueue($registrasi)) {
            return response()->json([
                'status' => false,
                'message' => 'Registrasi ini tidak berada pada antrian dokter',
            ], 422);
        }

        $task = $this->getDoctorTask($registrasi);

        if (!$task) {
            return response()->json([
                'status' => false,
                'message' => 'Task dokter tidak ditemukan',
            ], 422);
        }

        if (!in_array((int) $task->status, [
            RegistrasiTask::STATUS_MENUNGGU,
            RegistrasiTask::STATUS_PROSES,
        ], true)) {
            return response()->json([
                'status' => false,
                'message' => 'Task dokter tidak bisa diselesaikan',
            ], 422);
        }

        DB::beginTransaction();

        try {
            $soap = $this->saveDoctorSoap($request, $registrasi, $task);

            $this->replaceDoctorObatItems($request, $registrasi, $task, $soap);
            $this->replaceDoctorTreatmentItems($request, $registrasi, $task);

            $this->recalculateRegistrasiKunjungan($request, $registrasi);

            $paymentTask = $this->ensurePaymentTask($registrasi);

            $task->update([
                'status' => RegistrasiTask::STATUS_SELESAI,
                'started_at' => $task->started_at ?: now(),
                'finished_at' => now(),
                'updated_by' => $this->username(),
                'updated_at' => now(),
            ]);

            $nextTask = $registrasi->tasks()
                ->where('status', RegistrasiTask::STATUS_MENUNGGU)
                ->orderBy('task_order')
                ->first();

            if ($nextTask) {
                $registrasi->update([
                    'current_task' => $nextTask->task_type,
                    'status' => RegistrasiKunjungan::STATUS_AKTIF,
                    'updated_by' => $this->username(),
                    'updated_at' => now(),
                ]);
            } else {
                $registrasi->update([
                    'current_task' => RegistrasiTask::TYPE_PEMBAYARAN,
                    'status' => RegistrasiKunjungan::STATUS_AKTIF,
                    'updated_by' => $this->username(),
                    'updated_at' => now(),
                ]);
            }

            $registrasi = $registrasi->fresh([
                'toko',
                'pasien',
                'dokterAwal',
                'perawatAwal',
                'tasks',
                'treatmentDetails',
                'penjualanDetails',
            ]);

            $invoice = $this->syncPaymentInvoiceFromRegistrasi($registrasi, $paymentTask);

            DB::commit();

            $registrasi = $registrasi->fresh([
                'toko',
                'pasien',
                'dokterAwal',
                'perawatAwal',
                'tasks',
            ]);

            $row = $this->formatQueueRow($registrasi);
            $row->setAttribute('pembayaran_invoice_id', $invoice->id);
            $row->setAttribute('invoice_id', $invoice->id);
            $row->setAttribute('no_invoice', $invoice->no_invoice);

            return response()->json([
                'status' => true,
                'message' => 'Proses dokter berhasil disimpan dan invoice pembayaran berhasil disinkronkan',
                'data' => $row,
                'invoice' => [
                    'id' => $invoice->id,
                    'no_invoice' => $invoice->no_invoice,
                    'grand_total' => $invoice->grand_total,
                    'status' => $invoice->status,
                ],
            ]);
        } catch (\Throwable $e) {
            DB::rollBack();

            return response()->json([
                'status' => false,
                'message' => 'Gagal menyelesaikan proses dokter',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    public function destroy($id)
    {
        $registrasi = RegistrasiKunjungan::query()
            ->with('tasks')
            ->active()
            ->findOrFail($id);

        if (!$this->isDoctorQueue($registrasi)) {
            return response()->json([
                'status' => false,
                'message' => 'Registrasi ini tidak berada pada antrian dokter',
            ], 422);
        }

        $task = $this->getDoctorTask($registrasi);

        if (!$task) {
            return response()->json([
                'status' => false,
                'message' => 'Task dokter tidak ditemukan',
            ], 422);
        }

        if ((int) $task->status !== RegistrasiTask::STATUS_MENUNGGU) {
            return response()->json([
                'status' => false,
                'message' => 'Antrian tidak bisa dihapus karena pasien sudah mulai dilayani',
            ], 422);
        }

        DB::beginTransaction();

        try {
            $registrasi->update([
                'status' => RegistrasiKunjungan::STATUS_BATAL,
                'current_task' => RegistrasiKunjungan::TASK_DRAFT,
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
                'message' => 'Antrian dokter berhasil dihapus',
            ]);
        } catch (\Throwable $e) {
            DB::rollBack();

            return response()->json([
                'status' => false,
                'message' => 'Gagal menghapus antrian dokter',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    private function applyChannelFilter($query, $channel)
    {
        if ($channel === 'offline') {
            $query->where('channel_konsultasi', RegistrasiKunjungan::CHANNEL_OFFLINE);
            return;
        }

        if ($channel === 'online') {
            $query->where('channel_konsultasi', RegistrasiKunjungan::CHANNEL_ONLINE);
            return;
        }

        if (in_array($channel, ['tanpa_konsultasi', 'tanpa konsultasi'], true)) {
            $query->where('channel_konsultasi', RegistrasiKunjungan::CHANNEL_TIDAK_KONSULTASI);
        }
    }

    private function isDoctorQueue(RegistrasiKunjungan $registrasi)
    {
        $hasConsultation = in_array((int) $registrasi->channel_konsultasi, [
            RegistrasiKunjungan::CHANNEL_OFFLINE,
            RegistrasiKunjungan::CHANNEL_ONLINE,
        ], true);

        $hasTreatment = (int) $registrasi->is_treatment === 1;

        return $hasConsultation || $hasTreatment;
    }

    private function getDoctorTask(RegistrasiKunjungan $registrasi)
    {
        if ($registrasi->relationLoaded('tasks')) {
            return $registrasi->tasks
                ->where('task_type', RegistrasiTask::TYPE_KONSULTASI)
                ->sortBy('task_order')
                ->first();
        }

        return $registrasi->tasks()
            ->where('task_type', RegistrasiTask::TYPE_KONSULTASI)
            ->orderBy('task_order')
            ->first();
    }

    private function formatQueueRow(RegistrasiKunjungan $row)
    {
        $doctorTask = $this->getDoctorTask($row);

        $row->setAttribute('registrasi_id', $row->id);
        $row->setAttribute('antrian_dokter_id', $row->id);
        $row->setAttribute('queue_id', $row->id);

        $row->setAttribute('nomor_antrian', $row->kode_registrasi);
        $row->setAttribute('no_antrian', $row->kode_registrasi);

        $row->setAttribute('nama_pasien', $row->pasien?->nama);
        $row->setAttribute('no_rm', $row->pasien?->no_rm);
        $row->setAttribute('no_hp', $row->pasien?->no_hp);

        $row->setAttribute('nama_dokter', $row->dokterAwal?->nama);
        $row->setAttribute('dokter_id', $row->dokter_awal_id);

        $row->setAttribute('nama_perawat', $row->perawatAwal?->nama);
        $row->setAttribute('perawat_id', $row->perawat_awal_id);

        $row->setAttribute('waktu_kunjungan', $this->formatTime($row->registered_at));

        $row->setAttribute('ada_konsultasi', $this->hasConsultation($row));
        $row->setAttribute('ada_treatment', (int) $row->is_treatment === 1);
        $row->setAttribute('ada_penjualan', (int) $row->is_penjualan === 1);

        $row->setAttribute('channel_label', $this->formatChannel($row->channel_konsultasi));
        $row->setAttribute('layanan_label', $this->formatLayanan($row));

        $row->setAttribute('status_antrian_dokter', $this->mapTaskStatusToQueueStatus($doctorTask?->status));
        $row->setAttribute('status_task', $doctorTask?->status);
        $row->setAttribute('doctor_task_id', $doctorTask?->id);

        $row->setAttribute('can_delete_antrian', $doctorTask && (int) $doctorTask->status === RegistrasiTask::STATUS_MENUNGGU);
        $row->setAttribute('can_process_antrian', $doctorTask && in_array((int) $doctorTask->status, [
            RegistrasiTask::STATUS_MENUNGGU,
            RegistrasiTask::STATUS_PROSES,
        ], true));

        return $row;
    }

    private function hasConsultation(RegistrasiKunjungan $row)
    {
        return in_array((int) $row->channel_konsultasi, [
            RegistrasiKunjungan::CHANNEL_OFFLINE,
            RegistrasiKunjungan::CHANNEL_ONLINE,
        ], true);
    }

    private function formatChannel($channel)
    {
        return match ((int) $channel) {
            RegistrasiKunjungan::CHANNEL_OFFLINE => 'Konsultasi Offline',
            RegistrasiKunjungan::CHANNEL_ONLINE => 'Konsultasi Online',
            default => 'Tanpa Konsultasi',
        };
    }

    private function formatLayanan(RegistrasiKunjungan $row)
    {
        $hasConsultation = $this->hasConsultation($row);
        $hasTreatment = (int) $row->is_treatment === 1;
        $hasSales = (int) $row->is_penjualan === 1;

        if ($hasConsultation && $hasTreatment && $hasSales) {
            return 'Konsultasi + Treatment + Penjualan';
        }

        if ($hasConsultation && $hasTreatment) {
            return 'Konsultasi + Treatment';
        }

        if ($hasConsultation && $hasSales) {
            return 'Konsultasi + Penjualan';
        }

        if ($hasConsultation) {
            return 'Konsultasi';
        }

        if ($hasTreatment && $hasSales) {
            return 'Treatment + Penjualan';
        }

        if ($hasTreatment) {
            return 'Treatment Dokter';
        }

        if ($hasSales) {
            return 'Penjualan';
        }

        return 'Pelayanan Dokter';
    }

    private function mapQueueStatusToTaskStatus($status)
    {
        $status = strtolower(trim((string) $status));

        return match ($status) {
            'menunggu' => RegistrasiTask::STATUS_MENUNGGU,
            'dipanggil', 'proses', 'diproses' => RegistrasiTask::STATUS_PROSES,
            'selesai' => RegistrasiTask::STATUS_SELESAI,
            'batal' => RegistrasiTask::STATUS_BATAL,
            default => null,
        };
    }

    private function mapTaskStatusToQueueStatus($status)
    {
        return match ((int) $status) {
            RegistrasiTask::STATUS_MENUNGGU => 'menunggu',
            RegistrasiTask::STATUS_PROSES => 'proses',
            RegistrasiTask::STATUS_SELESAI => 'selesai',
            RegistrasiTask::STATUS_BATAL => 'batal',
            default => 'menunggu',
        };
    }

    private function formatTime($value)
    {
        if (!$value) {
            return null;
        }

        try {
            return Carbon::parse($value)->format('H:i');
        } catch (\Throwable $e) {
            return null;
        }
    }

    private function saveDoctorSoap(Request $request, RegistrasiKunjungan $registrasi, RegistrasiTask $task)
    {
        $soapPayload = $request->input('soap', []);

        $subjectiveItems = $this->normalizeTextArray(
            $soapPayload['subjective_items'] ?? $soapPayload['subjective'] ?? []
        );

        $assessmentItems = $this->normalizeTextArray(
            $soapPayload['assessment_items'] ?? $soapPayload['assessment'] ?? []
        );

        $soap = RegistrasiDokterSoap::updateOrCreate(
            [
                'registrasi_id' => $registrasi->id,
            ],
            [
                'task_id' => $task->id,
                'dokter_id' => $registrasi->dokter_awal_id ?: ($task->assigned_karyawan_id ?: 0),
                'subjective_id' => null,
                'subjective_lainnya' => $soapPayload['subjective_other'] ?? $soapPayload['subjective_lainnya'] ?? null,
                'objective' => $soapPayload['objective'] ?? null,
                'diagnosa_id' => null,
                'assessment_lainnya' => $soapPayload['assessment_other'] ?? $soapPayload['assessment_lainnya'] ?? null,
                'plan' => $soapPayload['planning'] ?? $soapPayload['plan'] ?? null,
                'next_konsultasi_date' => $soapPayload['next_date_konsultasi'] ?? null,
                'status' => 1,
                'finalized_at' => now(),
                'created_by' => $this->username(),
                'updated_by' => $this->username(),
                'updated_at' => now(),
            ]
        );

        RegistrasiDokterSoapSubjective::query()
            ->where('soap_id', $soap->id)
            ->delete();

        foreach ($subjectiveItems as $index => $text) {
            RegistrasiDokterSoapSubjective::create([
                'soap_id' => $soap->id,
                'subjective_id' => null,
                'subjective_text' => $text,
                'sort_order' => $index + 1,
                'created_at' => now(),
            ]);
        }

        RegistrasiDokterSoapDiagnosa::query()
            ->where('soap_id', $soap->id)
            ->delete();

        foreach ($assessmentItems as $index => $text) {
            RegistrasiDokterSoapDiagnosa::create([
                'soap_id' => $soap->id,
                'diagnosa_id' => null,
                'diagnosa_text' => $text,
                'sort_order' => $index + 1,
                'created_at' => now(),
            ]);
        }

        return $soap;
    }

    private function replaceDoctorObatItems(
        Request $request,
        RegistrasiKunjungan $registrasi,
        RegistrasiTask $task,
        RegistrasiDokterSoap $soap
    ) {
        $items = collect($request->input('obat_items', []))
            ->filter(function ($item) {
                return !empty($item['produk_toko_id']) || !empty($item['produk_id']);
            })
            ->values();
            
        $isSaranDokter = $this->isDoctorSuggestedProduct(
            $registrasi,
            $item['produk_toko_id'] ?? null,
            $item['produk_id'] ?? null
        );

        RegistrasiDokterResepDetail::query()
            ->where('registrasi_id', $registrasi->id)
            ->where('soap_id', $soap->id)
            ->update([
                'status' => 9,
                'is_delete' => 1,
                'updated_by' => $this->username(),
                'updated_at' => now(),
            ]);

        RegistrasiPenjualanDetail::query()
            ->where('registrasi_id', $registrasi->id)
            ->where('source_type', 2)
            ->where('source_task_id', $task->id)
            ->update([
                'status' => 9,
                'is_delete' => 1,
                'updated_by' => $this->username(),
                'updated_at' => now(),
            ]);

        foreach ($items as $item) {
            $harga = (float) ($item['harga'] ?? 0);
            $jumlah = (int) ($item['jumlah'] ?? $item['qty'] ?? 1);
            $jumlah = max($jumlah, 1);
            $subtotal = (float) ($item['subtotal'] ?? ($harga * $jumlah));
            $namaProduk = $item['nama_produk'] ?? $item['nama'] ?? 'Produk / Obat';

            $resep = RegistrasiDokterResepDetail::create([
                'registrasi_id' => $registrasi->id,
                'soap_id' => $soap->id,
                'produk_toko_id' => $item['produk_toko_id'] ?? null,
                'produk_id' => $item['produk_id'] ?? null,
                'nama_produk' => $namaProduk,
                'harga' => $harga,
                'jumlah' => $jumlah,
                'total' => $subtotal,
                'frekuensi' => $item['frekuensi'] ?? null,
                'waktu_pakai' => $item['waktu_pakai'] ?? null,
                'penggunaan' => $item['aturan_pakai'] ?? $item['penggunaan'] ?? null,
                'status' => 0,
                'is_delete' => 0,
                'created_by' => $this->username(),
                'created_at' => now(),
            ]);

            RegistrasiPenjualanDetail::create([
                'registrasi_id' => $registrasi->id,
                'source_type' => 2,
                'is_saran_dokter' => $isSaranDokter,
                'source_task_id' => $task->id,
                'source_resep_id' => $resep->id,
                'source_resep_detail_id' => $resep->id,
                'source_karyawan_id' => $registrasi->dokter_awal_id,
                'produk_toko_id' => $item['produk_toko_id'] ?? null,
                'produk_id' => $item['produk_id'] ?? null,
                'nama_produk' => $namaProduk,
                'harga' => $harga,
                'jumlah' => $jumlah,
                'diskon_tipe' => 0,
                'diskon_nilai' => 0,
                'diskon_referral' => 0,
                'subtotal' => $subtotal,
                'status' => 1,
                'is_delete' => 0,
                'created_by' => $this->username(),
                'created_at' => now(),
            ]);
        }
    }

    private function replaceDoctorTreatmentItems(Request $request, RegistrasiKunjungan $registrasi, RegistrasiTask $task)
    {
        $items = collect($request->input('treatment_items', []))
            ->filter(function ($item) {
                return !empty($item['treatment_toko_id']) || !empty($item['treatment_id']);
            })
            ->values();

        RegistrasiTreatmentDetail::query()
            ->where('registrasi_id', $registrasi->id)
            ->where('source_type', 2)
            ->where('source_task_id', $task->id)
            ->update([
                'status' => 9,
                'is_delete' => 1,
                'updated_by' => $this->username(),
                'updated_at' => now(),
            ]);

        foreach ($items as $item) {
            $harga = (float) ($item['harga'] ?? 0);
            $jumlah = (int) ($item['jumlah'] ?? $item['qty'] ?? 1);
            $jumlah = max($jumlah, 1);
            $total = (float) ($item['total'] ?? ($harga * $jumlah));
            $namaTreatment = $item['nama_treatment'] ?? $item['nama'] ?? 'Treatment';

            $isSaranDokter = $this->isDoctorSuggestedTreatment(
                $registrasi,
                $item['treatment_toko_id'] ?? null,
                $item['treatment_id'] ?? null
            );

            RegistrasiTreatmentDetail::create([
                'registrasi_id' => $registrasi->id,
                'source_type' => 2,
                'source_task_id' => $task->id,
                'source_karyawan_id' => $registrasi->dokter_awal_id,
                'is_deposit_claim' => 0,
                'deposit_treatment_id' => null,
                'deposit_claim_id' => null,
                'is_saran_dokter' => $isSaranDokter,
                'treatment_toko_id' => $item['treatment_toko_id'] ?? null,
                'treatment_id' => $item['treatment_id'] ?? null,
                'nama_treatment' => $namaTreatment,
                'harga' => $harga,
                'jumlah' => $jumlah,
                'total' => $total,
                'perlu_tindakan_perawat' => $this->isTrue($item['perlu_tindakan_perawat'] ?? false) ? 1 : 0,
                'route_treatment' => $item['route_treatment'] ?? null,
                'catatan' => $item['catatan'] ?? null,
                'status' => 0,
                'is_delete' => 0,
                'created_by' => $this->username(),
                'created_at' => now(),
            ]);
        }
    }

    private function recalculateRegistrasiKunjungan(Request $request, RegistrasiKunjungan $registrasi)
    {
        $totalTreatment = (float) RegistrasiTreatmentDetail::query()
            ->where('registrasi_id', $registrasi->id)
            ->where('is_delete', 0)
            ->where('status', '!=', 9)
            ->sum('total');

        $totalPenjualan = (float) RegistrasiPenjualanDetail::query()
            ->where('registrasi_id', $registrasi->id)
            ->where('is_delete', 0)
            ->where('status', '!=', 9)
            ->sum('subtotal');

        $hasTreatment = RegistrasiTreatmentDetail::query()
            ->where('registrasi_id', $registrasi->id)
            ->where('is_delete', 0)
            ->where('status', '!=', 9)
            ->exists();

        $hasPenjualan = RegistrasiPenjualanDetail::query()
            ->where('registrasi_id', $registrasi->id)
            ->where('is_delete', 0)
            ->where('status', '!=', 9)
            ->exists();

        $hasSaranDokterPenjualan = RegistrasiPenjualanDetail::query()
            ->where('registrasi_id', $registrasi->id)
            ->where('is_delete', 0)
            ->where('status', '!=', 9)
            ->where('is_saran_dokter', 1)
            ->exists();

        $hasSaranDokterTreatment = RegistrasiTreatmentDetail::query()
            ->where('registrasi_id', $registrasi->id)
            ->where('is_delete', 0)
            ->where('status', '!=', 9)
            ->where('is_saran_dokter', 1)
            ->exists();

        $hasSaranDokterKonsultasi = $addConsultation;

        $addConsultation = $this->isTrue($request->input('add_consultation'));
        $hasOriginalConsultation = $this->hasConsultation($registrasi);
        $hasConsultation = $hasOriginalConsultation || $addConsultation;

        $totalKonsultasi = 0;
        $ruleBiayaKonsultasi = 0;
        $catatanBiayaKonsultasi = null;

        if ($hasConsultation && $hasTreatment) {
            $totalKonsultasi = 0;
            $ruleBiayaKonsultasi = 2;
            $catatanBiayaKonsultasi = 'Gratis karena pasien mengambil treatment';
        } elseif ($hasConsultation) {
            $totalKonsultasi = (float) $request->input('biaya_konsultasi', 100000);

            if ($totalKonsultasi <= 0) {
                $totalKonsultasi = 100000;
            }

            $ruleBiayaKonsultasi = 1;
            $catatanBiayaKonsultasi = 'Biaya konsultasi dokter';
        }

        $channelKonsultasi = $registrasi->channel_konsultasi;

        if ($addConsultation && !$hasOriginalConsultation) {
            $channelKonsultasi = RegistrasiKunjungan::CHANNEL_OFFLINE;
        }

        $perluTindakanPerawat = RegistrasiTreatmentDetail::query()
            ->where('registrasi_id', $registrasi->id)
            ->where('is_delete', 0)
            ->where('status', '!=', 9)
            ->where('perlu_tindakan_perawat', 1)
            ->exists();

        $registrasi->update([
            'channel_konsultasi' => $channelKonsultasi,
            'is_konsultasi_tambahan_dokter' => $addConsultation ? 1 : (int) $registrasi->is_konsultasi_tambahan_dokter,
            'is_treatment' => $hasTreatment ? 1 : 0,
            'is_penjualan' => $hasPenjualan ? 1 : 0,
            'perlu_tindakan_perawat' => $perluTindakanPerawat ? 2 : (int) $registrasi->perlu_tindakan_perawat,
            'total_treatment' => $totalTreatment,
            'total_penjualan' => $totalPenjualan,
            'total_konsultasi' => $totalKonsultasi,
            'rule_biaya_konsultasi' => $ruleBiayaKonsultasi,
            'catatan_biaya_konsultasi' => $catatanBiayaKonsultasi,
            'grand_total' => $totalTreatment + $totalPenjualan + $totalKonsultasi,
            'has_saran_dokter' => (
                $hasSaranDokterPenjualan ||
                $hasSaranDokterTreatment ||
                $hasSaranDokterKonsultasi
            ) ? 1 : 0,
            'updated_by' => $this->username(),
            'updated_at' => now(),
        ]);
    }

    private function ensurePaymentTask(RegistrasiKunjungan $registrasi)
    {
        $paymentTask = $registrasi->tasks()
            ->where('task_type', RegistrasiTask::TYPE_PEMBAYARAN)
            ->first();

        if ($paymentTask) {
            if ((int) $paymentTask->status === RegistrasiTask::STATUS_BATAL) {
                $paymentTask->update([
                    'status' => RegistrasiTask::STATUS_MENUNGGU,
                    'updated_by' => $this->username(),
                    'updated_at' => now(),
                ]);
            }

            return $paymentTask;
        }

        $maxOrder = (int) $registrasi->tasks()->max('task_order');

        return RegistrasiTask::create([
            'registrasi_id' => $registrasi->id,
            'task_type' => RegistrasiTask::TYPE_PEMBAYARAN,
            'assigned_karyawan_id' => null,
            'task_order' => $maxOrder + 1,
            'status' => RegistrasiTask::STATUS_MENUNGGU,
            'created_by' => $this->username(),
            'created_at' => now(),
        ]);
    }

    private function syncPaymentInvoiceFromRegistrasi(RegistrasiKunjungan $registrasi, RegistrasiTask $paymentTask)
    {
        $invoice = PembayaranInvoice::query()
            ->active()
            ->where('registrasi_id', $registrasi->id)
            ->first();

        if ($invoice && (int) $invoice->status === PembayaranInvoice::STATUS_LUNAS) {
            throw new \Exception('Invoice sudah lunas dan tidak bisa disinkronkan ulang');
        }

        if ($invoice) {
            $invoice->items()->update([
                'status' => PembayaranInvoiceItem::STATUS_BATAL,
                'is_delete' => 1,
                'updated_by' => $this->username(),
                'updated_at' => now(),
            ]);

            $invoice->update([
                'task_id' => $paymentTask->id,
                'kode_registrasi' => $registrasi->kode_registrasi,
                'toko_id' => $registrasi->toko_id,
                'pasien_id' => $registrasi->pasien_id,
                'dokter_id' => $registrasi->dokter_awal_id,
                'catatan' => $registrasi->catatan_registrasi,
                'status' => PembayaranInvoice::STATUS_MENUNGGU,
                'updated_by' => $this->username(),
                'updated_at' => now(),
            ]);
        } else {
            $invoice = PembayaranInvoice::create([
                'registrasi_id' => $registrasi->id,
                'task_id' => $paymentTask->id,
                'no_invoice' => $this->generateInvoiceNumber($registrasi),
                'kode_registrasi' => $registrasi->kode_registrasi,
                'toko_id' => $registrasi->toko_id,
                'pasien_id' => $registrasi->pasien_id,
                'member_id' => null,
                'member_no' => null,
                'member_tier_id' => null,
                'member_tier_nama' => null,
                'dokter_id' => $registrasi->dokter_awal_id,
                'referensi_dokter_id' => null,
                'tanggal_invoice' => now(),
                'tanggal_lunas' => null,
                'jenis_transaksi' => 0,
                'deposit_expired_option_id' => null,
                'deposit_expired_at' => null,
                'sumber_kedatangan' => null,
                'poin' => 0,
                'catatan' => $registrasi->catatan_registrasi,
                'subtotal_produk' => 0,
                'subtotal_treatment' => 0,
                'subtotal_konsultasi' => 0,
                'subtotal' => 0,
                'diskon_subtotal_tipe' => 0,
                'diskon_subtotal_nilai' => 0,
                'diskon_subtotal_amount' => 0,
                'total_diskon_item' => 0,
                'total_diskon_referral' => 0,
                'total_promo' => 0,
                'diskon_member_amount' => 0,
                'point_earned' => 0,
                'point_redeemed' => 0,
                'point_redeem_value' => 0,
                'grand_total' => 0,
                'total_bayar' => 0,
                'sisa_tagihan' => 0,
                'total_kembalian' => 0,
                'status' => PembayaranInvoice::STATUS_MENUNGGU,
                'is_delete' => 0,
                'created_by' => $this->username(),
                'created_at' => now(),
            ]);
        }

        $this->insertConsultationInvoiceItem($invoice, $registrasi);
        $this->insertTreatmentInvoiceItems($invoice, $registrasi);
        $this->insertPenjualanInvoiceItems($invoice, $registrasi);
        $this->recalculatePaymentInvoice($invoice);

        return $invoice->fresh(['items']);
    }

    private function insertConsultationInvoiceItem(PembayaranInvoice $invoice, RegistrasiKunjungan $registrasi)
    {
        $hasConsultation =
            $this->hasConsultation($registrasi) ||
            (int) $registrasi->is_konsultasi_tambahan_dokter === 1 ||
            (float) $registrasi->total_konsultasi > 0;

        if (!$hasConsultation) {
            return;
        }

        $subtotal = (float) $registrasi->total_konsultasi;

        PembayaranInvoiceItem::create([
            'pembayaran_id' => $invoice->id,
            'registrasi_id' => $registrasi->id,
            'item_type' => PembayaranInvoiceItem::ITEM_KONSULTASI,
            'source_type' => PembayaranInvoiceItem::SOURCE_REGISTRASI_KONSULTASI,
            'is_saran_dokter' => (int) $registrasi->is_konsultasi_tambahan_dokter === 1 ? 1 : 0,
            'source_detail_id' => $registrasi->id,
            'deposit_treatment_id' => null,
            'deposit_claim_id' => null,
            'expired_at' => null,
            'treatment_id' => null,
            'treatment_toko_id' => null,
            'produk_id' => null,
            'produk_toko_id' => null,
            'nama_item' => $subtotal > 0 ? 'Konsultasi Dokter' : 'Konsultasi Dokter - Gratis Treatment',
            'satuan' => 'Konsultasi',
            'qty' => 1,
            'harga' => $subtotal,
            'diskon_tipe' => 0,
            'diskon_nilai' => 0,
            'diskon_amount' => 0,
            'diskon_referral' => 0,
            'subtotal' => $subtotal,
            'dokter_id' => $registrasi->dokter_awal_id,
            'perawat_id' => null,
            'frekuensi' => null,
            'waktu_pakai' => null,
            'instruksi_pemakaian' => null,
            'send_when_zero' => $subtotal <= 0 ? 1 : 0,
            'status' => PembayaranInvoiceItem::STATUS_AKTIF,
            'is_delete' => 0,
            'created_by' => $this->username(),
            'created_at' => now(),
        ]);
    }

    private function insertTreatmentInvoiceItems(PembayaranInvoice $invoice, RegistrasiKunjungan $registrasi)
    {
        $items = RegistrasiTreatmentDetail::query()
            ->where('registrasi_id', $registrasi->id)
            ->where('is_delete', 0)
            ->where('status', '!=', 9)
            ->get();

        foreach ($items as $item) {
            $qty = (float) ($item->jumlah ?? 1);
            $harga = (float) ($item->harga ?? 0);
            $subtotal = (float) ($item->total ?? ($qty * $harga));

            PembayaranInvoiceItem::create([
                'pembayaran_id' => $invoice->id,
                'registrasi_id' => $registrasi->id,
                'is_saran_dokter' => (int) ($item->is_saran_dokter ?? 0),
                'item_type' => PembayaranInvoiceItem::ITEM_TREATMENT,
                'source_type' => PembayaranInvoiceItem::SOURCE_REGISTRASI_TREATMENT,
                'source_detail_id' => $item->id,
                'deposit_treatment_id' => $item->deposit_treatment_id ?? null,
                'deposit_claim_id' => $item->deposit_claim_id ?? null,
                'expired_at' => null,
                'treatment_id' => $item->treatment_id,
                'treatment_toko_id' => $item->treatment_toko_id,
                'produk_id' => null,
                'produk_toko_id' => null,
                'nama_item' => $item->nama_treatment,
                'satuan' => 'Treatment',
                'qty' => $qty,
                'harga' => $harga,
                'diskon_tipe' => $item->diskon_tipe ?? 0,
                'diskon_nilai' => $item->diskon_nilai ?? 0,
                'diskon_amount' => $item->diskon_amount ?? 0,
                'diskon_referral' => $item->diskon_referral ?? 0,
                'subtotal' => $subtotal,
                'dokter_id' => $registrasi->dokter_awal_id,
                'perawat_id' => $registrasi->perawat_awal_id,
                'status' => PembayaranInvoiceItem::STATUS_AKTIF,
                'is_delete' => 0,
                'created_by' => $this->username(),
                'created_at' => now(),
            ]);
        }
    }

    private function insertPenjualanInvoiceItems(PembayaranInvoice $invoice, RegistrasiKunjungan $registrasi)
    {
        $items = RegistrasiPenjualanDetail::query()
            ->where('registrasi_id', $registrasi->id)
            ->where('is_delete', 0)
            ->where('status', '!=', 9)
            ->get();

        foreach ($items as $item) {
            $qty = (float) ($item->jumlah ?? 1);
            $harga = (float) ($item->harga ?? 0);
            $subtotal = (float) ($item->subtotal ?? ($qty * $harga));

            PembayaranInvoiceItem::create([
                'pembayaran_id' => $invoice->id,
                'registrasi_id' => $registrasi->id,
                'is_saran_dokter' => (int) ($item->is_saran_dokter ?? 0),
                'item_type' => PembayaranInvoiceItem::ITEM_PRODUK,
                'source_type' => PembayaranInvoiceItem::SOURCE_REGISTRASI_PENJUALAN,
                'source_detail_id' => $item->id,
                'deposit_treatment_id' => null,
                'deposit_claim_id' => null,
                'expired_at' => null,
                'treatment_id' => null,
                'treatment_toko_id' => null,
                'produk_id' => $item->produk_id,
                'produk_toko_id' => $item->produk_toko_id,
                'nama_item' => $item->nama_produk,
                'satuan' => $item->satuan ?? null,
                'qty' => $qty,
                'harga' => $harga,
                'diskon_tipe' => $item->diskon_tipe ?? 0,
                'diskon_nilai' => $item->diskon_nilai ?? 0,
                'diskon_amount' => $item->diskon_amount ?? 0,
                'diskon_referral' => $item->diskon_referral ?? 0,
                'subtotal' => $subtotal,
                'dokter_id' => $registrasi->dokter_awal_id,
                'perawat_id' => null,
                'frekuensi' => $item->frekuensi ?? null,
                'waktu_pakai' => $item->waktu_pakai ?? null,
                'instruksi_pemakaian' => $item->instruksi_pemakaian ?? null,
                'status' => PembayaranInvoiceItem::STATUS_AKTIF,
                'is_delete' => 0,
                'created_by' => $this->username(),
                'created_at' => now(),
            ]);
        }
    }

    private function recalculatePaymentInvoice(PembayaranInvoice $invoice)
    {
        $items = PembayaranInvoiceItem::query()
            ->where('pembayaran_id', $invoice->id)
            ->where('is_delete', 0)
            ->where('status', PembayaranInvoiceItem::STATUS_AKTIF)
            ->get();

        $subtotalProduk = (float) $items
            ->where('item_type', PembayaranInvoiceItem::ITEM_PRODUK)
            ->sum('subtotal');

        $subtotalTreatment = (float) $items
            ->where('item_type', PembayaranInvoiceItem::ITEM_TREATMENT)
            ->sum('subtotal');

        $subtotalKonsultasi = (float) $items
            ->where('item_type', PembayaranInvoiceItem::ITEM_KONSULTASI)
            ->sum('subtotal');

        $subtotal = $subtotalProduk + $subtotalTreatment + $subtotalKonsultasi;
        $totalDiskonItem = (float) $items->sum('diskon_amount');
        $totalDiskonReferral = (float) $items->sum('diskon_referral');

        $grandTotal = $subtotal - $totalDiskonItem - $totalDiskonReferral;

        if ($grandTotal < 0) {
            $grandTotal = 0;
        }

        $totalBayar = (float) ($invoice->total_bayar ?? 0);

        $invoice->update([
            'subtotal_produk' => $subtotalProduk,
            'subtotal_treatment' => $subtotalTreatment,
            'subtotal_konsultasi' => $subtotalKonsultasi,
            'subtotal' => $subtotal,
            'total_diskon_item' => $totalDiskonItem,
            'total_diskon_referral' => $totalDiskonReferral,
            'grand_total' => $grandTotal,
            'sisa_tagihan' => max(0, $grandTotal - $totalBayar),
            'updated_by' => $this->username(),
            'updated_at' => now(),
        ]);
    }

    private function generateInvoiceNumber(RegistrasiKunjungan $registrasi)
    {
        return 'INV-' . $registrasi->kode_registrasi;
    }

    private function normalizeTextArray($value)
    {
        if (!$value) {
            return [];
        }

        if (is_array($value)) {
            return collect($value)
                ->map(function ($item) {
                    if (is_array($item)) {
                        return $item['text'] ?? $item['label'] ?? $item['title'] ?? $item['value'] ?? null;
                    }

                    return $item;
                })
                ->filter(fn ($item) => $item !== null && $item !== '')
                ->map(fn ($item) => trim((string) $item))
                ->filter()
                ->values()
                ->all();
        }

        return collect(preg_split('/[|,;]/', (string) $value))
            ->map(fn ($item) => trim($item))
            ->filter()
            ->values()
            ->all();
    }

    private function isTrue($value)
    {
        return $value === true || $value === 1 || $value === '1' || $value === 'true';
    }

    private function username()
    {
        return auth()->user()->username
            ?? auth()->user()->name
            ?? 'system';
    }

    private function isDoctorSuggestedProduct(
        RegistrasiKunjungan $registrasi,
        $produkTokoId,
        $produkId
    ): int {
        $existsInInitialRegistration = RegistrasiPenjualanDetail::query()
            ->where('registrasi_id', $registrasi->id)
            ->where('source_type', 1)
            ->where('is_delete', 0)
            ->where('status', '!=', 9)
            ->where(function ($query) use ($produkTokoId, $produkId) {
                if ($produkTokoId) {
                    $query->orWhere('produk_toko_id', $produkTokoId);
                }

                if ($produkId) {
                    $query->orWhere('produk_id', $produkId);
                }
            })
            ->exists();

        return $existsInInitialRegistration ? 0 : 1;
    }

    private function isDoctorSuggestedTreatment(
        RegistrasiKunjungan $registrasi,
        $treatmentTokoId,
        $treatmentId
    ): int {
        $existsInInitialRegistration = RegistrasiTreatmentDetail::query()
            ->where('registrasi_id', $registrasi->id)
            ->where('source_type', 1)
            ->where('is_delete', 0)
            ->where('status', '!=', 9)
            ->where(function ($query) use ($treatmentTokoId, $treatmentId) {
                if ($treatmentTokoId) {
                    $query->orWhere('treatment_toko_id', $treatmentTokoId);
                }

                if ($treatmentId) {
                    $query->orWhere('treatment_id', $treatmentId);
                }
            })
            ->exists();

        return $existsInInitialRegistration ? 0 : 1;
    }
}