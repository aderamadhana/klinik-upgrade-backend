<?php

namespace App\Http\Controllers\Api\Registrasi;

use App\Http\Controllers\Controller;
use App\Models\Master\MasterAccurateItemMapping;
use App\Models\Master\MasterProdukToko;
use App\Models\Master\MasterTreatmentToko;
use App\Models\Pasien;
use App\Models\Registrasi\RegistrasiKonsultasiFoto;
use App\Models\Registrasi\RegistrasiKonsultasiIntake;
use App\Models\Registrasi\RegistrasiKunjungan;
use App\Models\Registrasi\RegistrasiPenjualanDetail;
use App\Models\Registrasi\RegistrasiTask;
use App\Models\Registrasi\RegistrasiTreatmentDetail;
use App\Models\Stock\StockProdukToko;
use App\Services\Stock\StockTransactionService;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;

class RegistrasiLayananController extends Controller
{
    protected StockTransactionService $stockTransactionService;

    public function __construct(StockTransactionService $stockTransactionService)
    {
        $this->stockTransactionService = $stockTransactionService;
    }

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
                    })
                    ->orWhereHas('dokterAwal', function ($d) use ($search) {
                        $d->where('nama', 'like', "%{$search}%");
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
            'layanan.konsultasi_source_code' => 'nullable|string|max:100',
            'layanan.konsultasi_source_name' => 'nullable|string|max:150',
            'layanan.konsultasi_mapping_id' => 'nullable|integer',
            'layanan.ada_treatment' => 'nullable|boolean',
            'layanan.ada_penjualan' => 'nullable|boolean',
            'layanan.route_treatment' => 'nullable|string|max:100',
            'layanan.is_pembelian_online' => 'nullable|boolean',

            'konsultasi_source_code' => 'nullable|string|max:100',
            'konsultasi_source_name' => 'nullable|string|max:150',
            'konsultasi_mapping_id' => 'nullable|integer',
            'total_konsultasi' => 'nullable|numeric|min:0',
            'rule_biaya_konsultasi' => 'nullable|integer',
            'is_pembelian_online' => 'nullable|boolean',

            'catatan_registrasi' => 'nullable|string',

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

        $channelKonsultasiInput = $layanan['channel_konsultasi'] ?? null;

        $konsultasiSourceCode = $layanan['konsultasi_source_code']
            ?? $request->input('konsultasi_source_code')
            ?? null;

        if ($adaKonsultasi && !$konsultasiSourceCode) {
            $konsultasiSourceCode = $channelKonsultasiInput === 'online'
                ? 'KONSULTASI_ONLINE'
                : 'KONSULTASI_OFFLINE';
        }

        $konsultasiMapping = null;

        if ($adaKonsultasi) {
            $konsultasiMapping = MasterAccurateItemMapping::findActiveBySourceCode(
                $konsultasiSourceCode
            );

            if (!$konsultasiMapping) {
                return response()->json([
                    'status' => false,
                    'message' => 'Mapping Accurate untuk layanan konsultasi belum diset.',
                    'errors' => [
                        'accurate_mapping' => [
                            "Mapping {$konsultasiSourceCode} belum ada atau belum aktif di master_accurate_item_mapping.",
                        ],
                    ],
                ], 422);
            }

            if (strtolower((string) $konsultasiMapping->source_type) !== 'konsultasi') {
                return response()->json([
                    'status' => false,
                    'message' => 'Source type mapping konsultasi tidak valid.',
                    'errors' => [
                        'accurate_mapping' => [
                            "Source code {$konsultasiSourceCode} harus memiliki source_type konsultasi.",
                        ],
                    ],
                ], 422);
            }

            if (
                $this->toBool($konsultasiMapping->is_send_to_accurate ?? false)
                && empty($konsultasiMapping->kode_accurate)
            ) {
                return response()->json([
                    'status' => false,
                    'message' => 'Kode Accurate konsultasi belum diisi.',
                    'errors' => [
                        'accurate_mapping' => [
                            "Kode Accurate untuk {$konsultasiSourceCode} masih kosong.",
                        ],
                    ],
                ], 422);
            }
        }

        $channelKonsultasi = $this->resolveChannelKonsultasiFromMapping(
            $adaKonsultasi,
            $channelKonsultasiInput,
            $konsultasiSourceCode
        );

        $isPembelianOnline = $this->toBool(
            $layanan['is_pembelian_online']
                ?? $request->input('is_pembelian_online', false)
        );

        $pembelianOnlineMapping = null;

        if ($isPembelianOnline) {
            $pembelianOnlineMapping = MasterAccurateItemMapping::findActiveBySourceCode(
                'PEMBELIAN_ONLINE'
            );

            if (!$pembelianOnlineMapping) {
                return response()->json([
                    'status' => false,
                    'message' => 'Mapping Accurate untuk pembelian online belum diset.',
                    'errors' => [
                        'accurate_mapping' => [
                            'Mapping PEMBELIAN_ONLINE belum ada atau belum aktif di master_accurate_item_mapping.',
                        ],
                    ],
                ], 422);
            }

            if (strtolower((string) $pembelianOnlineMapping->source_type) !== 'channel') {
                return response()->json([
                    'status' => false,
                    'message' => 'Source type mapping pembelian online tidak valid.',
                    'errors' => [
                        'accurate_mapping' => [
                            'PEMBELIAN_ONLINE harus memiliki source_type channel.',
                        ],
                    ],
                ], 422);
            }

            if (
                $this->toBool($pembelianOnlineMapping->is_send_to_accurate ?? false)
                && empty($pembelianOnlineMapping->kode_accurate)
            ) {
                return response()->json([
                    'status' => false,
                    'message' => 'Kode Accurate pembelian online belum diisi.',
                    'errors' => [
                        'accurate_mapping' => [
                            'Kode Accurate untuk PEMBELIAN_ONLINE masih kosong.',
                        ],
                    ],
                ], 422);
            }
        }

        if (($adaKonsultasi || $adaTreatment) && !$request->filled('dokter_id')) {
            return response()->json([
                'status' => false,
                'message' => 'Dokter wajib dipilih untuk layanan konsultasi atau treatment',
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
            $tokoId = (int) $request->toko_id;

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

            if ($adaPenjualan) {
                $penjualanItems = $this->ensureAndValidatePenjualanStockFromMaster(
                    $penjualanItems,
                    $tokoId
                );
            }

            $needNurseStation = collect($treatmentItems)->contains(function ($item) {
                return $this->toBool($item['perlu_tindakan_perawat'] ?? false)
                    || ($item['route_treatment'] ?? null) === 'nurse_station';
            });

            $totalTreatment = collect($treatmentItems)->sum('total');
            $totalPenjualan = collect($penjualanItems)->sum('subtotal');

            $totalKonsultasi = $this->resolveTotalKonsultasi(
                $adaKonsultasi,
                $adaTreatment,
                $konsultasiMapping
            );

            $grandTotal = $totalTreatment + $totalPenjualan + $totalKonsultasi;

            $currentTask = $this->determineCurrentTask(
                $adaKonsultasi,
                $adaTreatment,
                $adaPenjualan
            );

            $needPembayaran = $adaTreatment || $adaPenjualan || $totalKonsultasi > 0;

            $registrasiTable = (new RegistrasiKunjungan())->getTable();

            $registrasiPayload = [
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
            ];

            $this->putIfColumn($registrasiPayload, $registrasiTable, 'is_konsultasi', $adaKonsultasi ? 1 : 0);

            $this->putIfColumn($registrasiPayload, $registrasiTable, 'konsultasi_source_code', $konsultasiMapping?->source_code);
            $this->putIfColumn($registrasiPayload, $registrasiTable, 'konsultasi_source_name', $konsultasiMapping?->source_name);
            $this->putIfColumn($registrasiPayload, $registrasiTable, 'konsultasi_mapping_id', $konsultasiMapping?->id);
            $this->putIfColumn($registrasiPayload, $registrasiTable, 'konsultasi_accurate_mapping_id', $konsultasiMapping?->id);
            $this->putIfColumn($registrasiPayload, $registrasiTable, 'kode_accurate_konsultasi', $konsultasiMapping?->kode_accurate);
            $this->putIfColumn($registrasiPayload, $registrasiTable, 'nama_accurate_konsultasi', $konsultasiMapping?->nama_accurate);
            $this->putIfColumn($registrasiPayload, $registrasiTable, 'total_konsultasi', $totalKonsultasi);
            $this->putIfColumn(
                $registrasiPayload,
                $registrasiTable,
                'rule_biaya_konsultasi',
                $this->resolveRuleBiayaKonsultasi($adaKonsultasi, $adaTreatment, $totalKonsultasi)
            );
            $this->putIfColumn(
                $registrasiPayload,
                $registrasiTable,
                'catatan_biaya_konsultasi',
                $this->resolveCatatanBiayaKonsultasi($adaKonsultasi, $adaTreatment, $totalKonsultasi, $konsultasiMapping)
            );

            $this->putIfColumn($registrasiPayload, $registrasiTable, 'is_pembelian_online', $isPembelianOnline ? 1 : 0);
            $this->putIfColumn($registrasiPayload, $registrasiTable, 'pembelian_online_source_code', $pembelianOnlineMapping?->source_code);
            $this->putIfColumn($registrasiPayload, $registrasiTable, 'pembelian_online_source_name', $pembelianOnlineMapping?->source_name);
            $this->putIfColumn($registrasiPayload, $registrasiTable, 'pembelian_online_mapping_id', $pembelianOnlineMapping?->id);
            $this->putIfColumn($registrasiPayload, $registrasiTable, 'kode_accurate_pembelian_online', $pembelianOnlineMapping?->kode_accurate);
            $this->putIfColumn($registrasiPayload, $registrasiTable, 'nama_accurate_pembelian_online', $pembelianOnlineMapping?->nama_accurate);

            $registrasi = RegistrasiKunjungan::create($registrasiPayload);

            $tasks = $this->createTasks(
                $registrasi,
                $adaKonsultasi,
                $adaTreatment,
                $adaPenjualan,
                $needNurseStation,
                $needPembayaran,
                $request
            );

            $konsultasi = null;

            if ($adaKonsultasi) {
                $konsultasi = $this->createKonsultasiIntake(
                    $registrasi,
                    $request,
                    $channelKonsultasi
                );
            }

            if ($konsultasi && $channelKonsultasi === RegistrasiKunjungan::CHANNEL_ONLINE) {
                $this->storeKonsultasiFotos($request, $registrasi, $konsultasi);
            }

            if ($adaTreatment) {
                $this->createTreatmentDetails($registrasi, $treatmentItems, $tasks);
            }

            $createdPenjualanDetails = [];

            if ($adaPenjualan) {
                $createdPenjualanDetails = $this->createPenjualanDetails(
                    $registrasi,
                    $penjualanItems,
                    $tasks
                );

                $this->reservePenjualanStock($registrasi, $createdPenjualanDetails);
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

    private function ensureAndValidatePenjualanStockFromMaster(array $items, int $tokoId): array
    {
        $qtyByStockId = [];

        foreach ($items as $index => $item) {
            $produkTokoId = $item['produk_toko_id']
                ?? $item['master_produk_toko_id']
                ?? $item['obat_toko_id']
                ?? null;

            $produkId = $item['produk_id']
                ?? $item['obat_id']
                ?? $item['master_produk_id']
                ?? null;

            $qty = (float) ($item['jumlah'] ?? $item['qty'] ?? 0);

            if (!$produkTokoId || $qty <= 0) {
                continue;
            }

            $produkToko = MasterProdukToko::with('produk')
                ->active()
                ->where('id', $produkTokoId)
                ->where('toko_id', $tokoId)
                ->when($produkId, function ($q) use ($produkId) {
                    $q->where('produk_id', $produkId);
                })
                ->first();

            if (!$produkToko || !$produkToko->produk) {
                throw new \Exception("Produk toko ID {$produkTokoId} tidak valid untuk cabang ini.");
            }

            $produkId = $produkId ?: $produkToko->produk_id;
            $tempatProdukId = $item['tempat_produk_id']
                ?? $produkToko->produk->tempat_produk_id
                ?? 1;

            $stock = StockProdukToko::query()
                ->where('produk_toko_id', $produkToko->id)
                ->where('produk_id', $produkId)
                ->where('toko_id', $tokoId)
                ->where('tempat_produk_id', $tempatProdukId)
                ->where(function ($q) {
                    $q->where('is_delete', 0)
                        ->orWhereNull('is_delete');
                })
                ->lockForUpdate()
                ->first();

            if (!$stock) {
                $stock = StockProdukToko::create([
                    'produk_toko_id' => $produkToko->id,
                    'produk_id' => $produkId,
                    'toko_id' => $tokoId,
                    'tempat_produk_id' => $tempatProdukId,
                    'stok_awal' => (float) ($produkToko->stok_awal ?? 0),
                    'stok_masuk' => 0,
                    'stok_keluar' => 0,
                    'stok_penyesuaian' => 0,
                    'stok_akhir' => (float) ($produkToko->stok_awal ?? 0),
                    'stok_reserved' => 0,
                    'stok_minimum' => (float) ($produkToko->stok_minimum ?? 0),
                    'harga_beli_terakhir' => (float) ($produkToko->harga_beli ?? 0),
                    'harga_jual_terakhir' => (float) ($produkToko->harga_jual ?? 0),
                    'last_mutation_at' => now(),
                    'is_delete' => 0,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);

                $stock = StockProdukToko::query()
                    ->where('id', $stock->id)
                    ->lockForUpdate()
                    ->first();
            }

            $items[$index]['produk_toko_id'] = $produkToko->id;
            $items[$index]['produk_id'] = $produkId;
            $items[$index]['obat_id'] = $produkId;
            $items[$index]['tempat_produk_id'] = $tempatProdukId;
            $items[$index]['stock_produk_toko_id'] = $stock->id;

            if (!isset($qtyByStockId[$stock->id])) {
                $qtyByStockId[$stock->id] = 0;
            }

            $qtyByStockId[$stock->id] += $qty;
        }

        foreach ($qtyByStockId as $stockId => $totalQty) {
            $stock = StockProdukToko::query()
                ->where('id', $stockId)
                ->lockForUpdate()
                ->first();

            if (!$stock) {
                throw new \Exception("Saldo stok ID {$stockId} tidak ditemukan.");
            }

            $stokAkhir = (float) ($stock->stok_akhir ?? 0);
            $stokReserved = (float) ($stock->stok_reserved ?? 0);
            $stokTersedia = max($stokAkhir - $stokReserved, 0);

            if ($stokTersedia < $totalQty) {
                throw new \Exception(
                    "Stok tidak cukup untuk produk ID {$stock->produk_id}. Stok tersedia: {$stokTersedia}, diminta: {$totalQty}."
                );
            }
        }

        return $items;
    }

    public function antrianDokter(Request $request)
    {
        $query = RegistrasiKunjungan::query()
            ->with([
                'toko:id,nama_toko',
                'pasien:id,no_rm,nama,no_hp',
                'dokterAwal:id,nama',
                'perawatAwal:id,nama',
                'tasks' => function ($q) {
                    $q->orderBy('task_order');
                },
                'tasks.assignedKaryawan:id,nama',
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

        if ($request->filled('status')) {
            $taskStatus = $this->mapQueueStatusToTaskStatus($request->status);

            if ($taskStatus !== null) {
                $query->whereHas('tasks', function ($q) use ($taskStatus) {
                    $q->where('task_type', RegistrasiTask::TYPE_KONSULTASI)
                        ->where('status', $taskStatus);
                });
            }
        }

        if ($request->filled('channel')) {
            if ($request->channel === 'offline') {
                $query->where('channel_konsultasi', RegistrasiKunjungan::CHANNEL_OFFLINE);
            }

            if ($request->channel === 'online') {
                $query->where('channel_konsultasi', RegistrasiKunjungan::CHANNEL_ONLINE);
            }

            if ($request->channel === 'tanpa_konsultasi') {
                $query->where('channel_konsultasi', RegistrasiKunjungan::CHANNEL_TIDAK_KONSULTASI);
            }
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
            ->paginate($request->get('per_page', 15));

        $rows->getCollection()->transform(function ($row) {
            return $this->decorateAntrianDokterRow($row);
        });

        return response()->json([
            'status' => true,
            'message' => 'Data antrian dokter berhasil diambil',
            'data' => $rows,
        ]);
    }

    public function startTask($taskId)
    {
        $task = RegistrasiTask::with('registrasi')->findOrFail($taskId);

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

    public function startCurrentTask($id)
    {
        $registrasi = RegistrasiKunjungan::query()
            ->with('tasks')
            ->active()
            ->findOrFail($id);

        if ((int) $registrasi->status !== RegistrasiKunjungan::STATUS_AKTIF) {
            return response()->json([
                'status' => false,
                'message' => 'Registrasi tidak aktif',
            ], 422);
        }

        $task = $registrasi->tasks()
            ->where('task_type', $registrasi->current_task)
            ->where('status', RegistrasiTask::STATUS_MENUNGGU)
            ->orderBy('task_order')
            ->first();

        if (!$task) {
            return response()->json([
                'status' => false,
                'message' => 'Task aktif tidak ditemukan atau sudah diproses',
            ], 422);
        }

        return $this->startTask($task->id);
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
                    'current_task' => RegistrasiKunjungan::TASK_DRAFT,
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

    public function finishCurrentTask($id)
    {
        $registrasi = RegistrasiKunjungan::query()
            ->with('tasks')
            ->active()
            ->findOrFail($id);

        $task = $registrasi->tasks()
            ->where('task_type', $registrasi->current_task)
            ->whereIn('status', [
                RegistrasiTask::STATUS_MENUNGGU,
                RegistrasiTask::STATUS_PROSES,
            ])
            ->orderBy('task_order')
            ->first();

        if (!$task) {
            return response()->json([
                'status' => false,
                'message' => 'Task aktif tidak ditemukan',
            ], 422);
        }

        return $this->finishTask($task->id);
    }

    public function cancel($id)
    {
        $registrasi = RegistrasiKunjungan::query()
            ->with('tasks')
            ->active()
            ->findOrFail($id);

        if ($this->hasStartedTask($registrasi)) {
            return response()->json([
                'status' => false,
                'message' => 'Registrasi tidak bisa dibatalkan karena pasien sudah mulai dilayani',
            ], 422);
        }

        DB::beginTransaction();

        try {
            $this->releasePenjualanReserve($registrasi, 'Release stok karena registrasi dibatalkan');

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

    public function destroyAntrianDokter($id)
    {
        $registrasi = RegistrasiKunjungan::query()
            ->with('tasks')
            ->active()
            ->findOrFail($id);

        if ((int) $registrasi->current_task !== RegistrasiKunjungan::TASK_KONSULTASI) {
            return response()->json([
                'status' => false,
                'message' => 'Antrian tidak bisa dihapus karena sudah berpindah proses',
            ], 422);
        }

        $doctorTask = $this->getDoctorTask($registrasi);

        if (!$doctorTask) {
            return response()->json([
                'status' => false,
                'message' => 'Task dokter tidak ditemukan',
            ], 422);
        }

        if ((int) $doctorTask->status !== RegistrasiTask::STATUS_MENUNGGU) {
            return response()->json([
                'status' => false,
                'message' => 'Antrian tidak bisa dihapus karena pasien sudah mulai dilayani',
            ], 422);
        }

        DB::beginTransaction();

        try {
            $this->releasePenjualanReserve($registrasi, 'Release stok karena registrasi dibatalkan');

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

    public function deleteAntrianDokter($id)
    {
        return $this->destroyAntrianDokter($id);
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
        bool $needPembayaran,
        Request $request
    ) {
        $tasks = [];
        $order = 1;

        if ($adaKonsultasi || $adaTreatment) {
            $tasks['dokter'] = RegistrasiTask::create([
                'registrasi_id' => $registrasi->id,
                'task_type' => RegistrasiTask::TYPE_KONSULTASI,
                'assigned_karyawan_id' => $request->input('dokter_id'),
                'task_order' => $order++,
                'status' => RegistrasiTask::STATUS_MENUNGGU,
                'created_by' => $this->username(),
                'created_at' => now(),
            ]);
        }

        if ($needPembayaran) {
            $tasks['pembayaran'] = RegistrasiTask::create([
                'registrasi_id' => $registrasi->id,
                'task_type' => RegistrasiTask::TYPE_PEMBAYARAN,
                'assigned_karyawan_id' => null,
                'task_order' => $order++,
                'status' => RegistrasiTask::STATUS_MENUNGGU,
                'created_by' => $this->username(),
                'created_at' => now(),
            ]);
        }

        if ($adaTreatment) {
            $tasks['perawat'] = RegistrasiTask::create([
                'registrasi_id' => $registrasi->id,
                'task_type' => RegistrasiTask::TYPE_TINDAKAN_PERAWAT,
                'assigned_karyawan_id' => $request->input('perawat_id'),
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
            'jenis_konsultasi' => $channel === RegistrasiKunjungan::CHANNEL_ONLINE
                ? RegistrasiKonsultasiIntake::JENIS_ONLINE
                : RegistrasiKonsultasiIntake::JENIS_OFFLINE,
            'status' => RegistrasiKonsultasiIntake::STATUS_MENUNGGU,
            'created_by' => $this->username(),
            'created_at' => now(),
        ];

        if ($channel === RegistrasiKunjungan::CHANNEL_OFFLINE) {
            $offline = $request->input('konsultasi_offline', []);

            $payload['keluhan_awal'] = $offline['keluhan_awal'] ?? null;
            $payload['catatan_awal'] = $offline['catatan'] ?? null;
        }

        if ($channel === RegistrasiKunjungan::CHANNEL_ONLINE) {
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
        $task = $tasks['dokter'] ?? $tasks['perawat'] ?? null;

        foreach ($items as $item) {
            $ids = $this->resolveTreatmentIds($item, $registrasi->toko_id);

            if (empty($ids['treatment_toko_id']) || empty($ids['treatment_id'])) {
                throw new \Exception(
                    'Mapping treatment toko tidak ditemukan untuk treatment_id: ' .
                    ($item['treatment_id'] ?? $item['tindakan_id'] ?? $item['id'] ?? '-')
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
                'nama_treatment' => $item['nama_treatment']
                    ?? $item['treatment_nama']
                    ?? $item['nama_tindakan']
                    ?? null,
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
        $task = $tasks['penjualan'] ?? $tasks['pembayaran'] ?? null;
        $createdDetails = [];

        foreach ($items as $item) {
            $ids = $this->resolveProdukIds($item, $registrasi->toko_id);

            if (empty($ids['produk_toko_id']) || empty($ids['produk_id'])) {
                throw new \Exception(
                    'Mapping produk toko tidak ditemukan untuk produk_id: ' .
                    ($item['produk_id'] ?? $item['obat_id'] ?? $item['id'] ?? '-')
                );
            }

            $detail = RegistrasiPenjualanDetail::create([
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

            $createdDetails[] = [
                'detail_id' => $detail->id,
                'produk_toko_id' => $ids['produk_toko_id'],
                'produk_id' => $ids['produk_id'],
                'tempat_produk_id' => $this->resolveTempatProdukId($item, $ids),
                'jumlah' => (float) $item['jumlah'],
                'harga' => (float) $item['harga'],
            ];
        }

        return $createdDetails;
    }

    private function reservePenjualanStock(RegistrasiKunjungan $registrasi, array $createdPenjualanDetails)
    {
        if (empty($createdPenjualanDetails)) {
            return;
        }

        $items = [];

        foreach ($createdPenjualanDetails as $detail) {
            $items[] = [
                'produk_toko_id' => $detail['produk_toko_id'],
                'produk_id' => $detail['produk_id'],
                'tempat_produk_id' => $detail['tempat_produk_id'],
                'qty' => $detail['jumlah'],
                'harga_jual' => $detail['harga'],
                'source_detail_id' => $detail['detail_id'],
            ];
        }

        $this->stockTransactionService->reserveProduk($items, [
            'toko_id' => $registrasi->toko_id,
            'source_type' => 'REGISTRASI_LAYANAN',
            'source_table' => 'registrasi_kunjungan',
            'source_id' => $registrasi->id,
            'kode_reservasi' => $registrasi->kode_registrasi,
            'tanggal' => now(),
            'expired_at' => Carbon::parse($registrasi->registered_at ?? now())->addHours(6),
            'keterangan' => 'Reserve stok dari registrasi layanan',
            'created_by' => $this->username(),
        ]);
    }

    private function releasePenjualanReserve(RegistrasiKunjungan $registrasi, string $keterangan)
    {
        $this->stockTransactionService->releaseReservasiBySource(
            'REGISTRASI_LAYANAN',
            $registrasi->id,
            [
                'kode_mutasi' => $registrasi->kode_registrasi,
                'tanggal' => now(),
                'source_table' => 'registrasi_kunjungan',
                'keterangan' => $keterangan,
                'created_by' => $this->username(),
            ]
        );
    }

    private function resolveTempatProdukId(array $item, array $ids)
    {
        $explicitTempatId = $item['tempat_produk_id'] ?? null;

        if (!empty($explicitTempatId)) {
            return (int) $explicitTempatId;
        }

        $produkId = $ids['produk_id'] ?? null;

        if (!empty($ids['produk_toko_id'])) {
            $produkTokoTable = (new MasterProdukToko())->getTable();

            if (Schema::hasTable($produkTokoTable)) {
                $produkToko = DB::table($produkTokoTable)
                    ->where('id', $ids['produk_toko_id'])
                    ->first();

                if ($produkToko) {
                    if (
                        Schema::hasColumn($produkTokoTable, 'tempat_produk_id')
                        && !empty($produkToko->tempat_produk_id)
                    ) {
                        return (int) $produkToko->tempat_produk_id;
                    }

                    $produkId = $produkToko->produk_id
                        ?? $produkToko->master_produk_id
                        ?? $produkToko->obat_id
                        ?? $produkId;
                }
            }
        }

        $tempatProdukId = $this->getTempatProdukIdFromMasterProduk($produkId);

        if ($tempatProdukId) {
            return $tempatProdukId;
        }

        return 1;
    }

    private function getTempatProdukIdFromMasterProduk($produkId)
    {
        if (empty($produkId) || !Schema::hasTable('master_produk')) {
            return null;
        }

        $produk = DB::table('master_produk')
            ->where('id', $produkId)
            ->first();

        if (!$produk) {
            return null;
        }

        if (
            Schema::hasColumn('master_produk', 'tempat_produk_id')
            && !empty($produk->tempat_produk_id)
        ) {
            return (int) $produk->tempat_produk_id;
        }

        return null;
    }

    private function normalizeTreatmentItems(array $items)
    {
        $result = [];

        foreach ($items as $item) {
            $treatmentTokoId = $item['treatment_toko_id']
                ?? $item['master_treatment_toko_id']
                ?? $item['tindakan_toko_id']
                ?? $item['toko_treatment_id']
                ?? null;

            $treatmentId = $item['treatment_id']
                ?? $item['tindakan_id']
                ?? $item['master_treatment_id']
                ?? null;

            $candidateId = $item['id'] ?? null;

            if (!$treatmentId && !$treatmentTokoId && !$candidateId) {
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
                'candidate_id' => $candidateId,
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
            $produkTokoId = $item['produk_toko_id']
                ?? $item['master_produk_toko_id']
                ?? $item['obat_toko_id']
                ?? $item['toko_produk_id']
                ?? null;

            $produkId = $item['produk_id']
                ?? $item['obat_id']
                ?? $item['master_produk_id']
                ?? null;

            $candidateId = $item['id'] ?? null;

            if (!$produkId && !$produkTokoId && !$candidateId) {
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
                'candidate_id' => $candidateId,
                'harga' => $harga,
                'jumlah' => $jumlah,
                'subtotal' => $subtotal,
            ];
        }

        return $result;
    }

    private function resolveTreatmentIds(array $item, $tokoId = null)
    {
        $treatmentTokoId = $item['treatment_toko_id']
            ?? $item['master_treatment_toko_id']
            ?? $item['tindakan_toko_id']
            ?? $item['toko_treatment_id']
            ?? null;

        $treatmentId = $item['treatment_id']
            ?? $item['tindakan_id']
            ?? $item['master_treatment_id']
            ?? null;

        $candidateId = $item['candidate_id'] ?? $item['id'] ?? null;

        $table = (new MasterTreatmentToko())->getTable();

        $row = null;

        if ($treatmentTokoId) {
            $row = MasterTreatmentToko::query()->find($treatmentTokoId);
        }

        if (!$row && !$treatmentId && $candidateId) {
            $row = MasterTreatmentToko::query()->find($candidateId);
        }

        if (!$row && $tokoId && ($treatmentId || $candidateId)) {
            $searchId = $treatmentId ?: $candidateId;

            $query = MasterTreatmentToko::query();

            if (Schema::hasColumn($table, 'toko_id')) {
                $query->where('toko_id', $tokoId);
            }

            if (Schema::hasColumn($table, 'is_delete')) {
                $query->where(function ($q) {
                    $q->where('is_delete', 0)->orWhereNull('is_delete');
                });
            }

            $query->where(function ($q) use ($table, $searchId) {
                if (Schema::hasColumn($table, 'treatment_id')) {
                    $q->orWhere('treatment_id', $searchId);
                }

                if (Schema::hasColumn($table, 'master_treatment_id')) {
                    $q->orWhere('master_treatment_id', $searchId);
                }
            });

            $row = $query->first();
        }

        return [
            'treatment_toko_id' => $row?->id ?? $treatmentTokoId,
            'treatment_id' => $row?->treatment_id
                ?? $row?->master_treatment_id
                ?? $treatmentId
                ?? null,
        ];
    }

    private function resolveProdukIds(array $item, $tokoId = null)
    {
        $produkTokoId = $item['produk_toko_id']
            ?? $item['master_produk_toko_id']
            ?? $item['obat_toko_id']
            ?? $item['toko_produk_id']
            ?? null;

        $produkId = $item['produk_id']
            ?? $item['obat_id']
            ?? $item['master_produk_id']
            ?? null;

        $candidateId = $item['candidate_id'] ?? $item['id'] ?? null;

        $table = (new MasterProdukToko())->getTable();

        $row = null;

        if ($produkTokoId) {
            $row = MasterProdukToko::query()->find($produkTokoId);
        }

        if (!$row && !$produkId && $candidateId) {
            $row = MasterProdukToko::query()->find($candidateId);
        }

        if (!$row && $tokoId && ($produkId || $candidateId)) {
            $searchId = $produkId ?: $candidateId;

            $query = MasterProdukToko::query();

            if (Schema::hasColumn($table, 'toko_id')) {
                $query->where('toko_id', $tokoId);
            }

            if (Schema::hasColumn($table, 'is_delete')) {
                $query->where(function ($q) {
                    $q->where('is_delete', 0)->orWhereNull('is_delete');
                });
            }

            $query->where(function ($q) use ($table, $searchId) {
                if (Schema::hasColumn($table, 'produk_id')) {
                    $q->orWhere('produk_id', $searchId);
                }

                if (Schema::hasColumn($table, 'master_produk_id')) {
                    $q->orWhere('master_produk_id', $searchId);
                }

                if (Schema::hasColumn($table, 'obat_id')) {
                    $q->orWhere('obat_id', $searchId);
                }
            });

            $row = $query->first();
        }

        return [
            'produk_toko_id' => $row?->id ?? $produkTokoId,
            'produk_id' => $row?->produk_id
                ?? $row?->master_produk_id
                ?? $row?->obat_id
                ?? $produkId
                ?? null,
        ];
    }

    private function determineCurrentTask(
        bool $adaKonsultasi,
        bool $adaTreatment,
        bool $adaPenjualan
    ) {
        if ($adaKonsultasi || $adaTreatment) {
            return RegistrasiKunjungan::TASK_KONSULTASI;
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

    private function decorateAntrianDokterRow(RegistrasiKunjungan $row)
    {
        $doctorTask = $this->getDoctorTask($row);

        $row->setAttribute('registrasi_id', $row->id);
        $row->setAttribute('nomor_antrian', $row->kode_registrasi);
        $row->setAttribute('nama_pasien', $row->pasien?->nama);
        $row->setAttribute('no_rm', $row->pasien?->no_rm);
        $row->setAttribute('no_hp', $row->pasien?->no_hp);
        $row->setAttribute('nama_dokter', $row->dokterAwal?->nama);
        $row->setAttribute('nama_perawat', $row->perawatAwal?->nama);
        $row->setAttribute('waktu_kunjungan', $this->formatTimeValue($row->registered_at));
        $row->setAttribute('ada_konsultasi', (int) $row->channel_konsultasi > 0);
        $row->setAttribute('ada_treatment', (int) $row->is_treatment === 1);
        $row->setAttribute('ada_penjualan', (int) $row->is_penjualan === 1);
        $row->setAttribute('status_antrian_dokter', $this->mapTaskStatusToQueueStatus($doctorTask?->status));
        $row->setAttribute('can_delete_antrian', $doctorTask && (int) $doctorTask->status === RegistrasiTask::STATUS_MENUNGGU);

        return $row;
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

    private function hasStartedTask(RegistrasiKunjungan $registrasi)
    {
        if ($registrasi->relationLoaded('tasks')) {
            return $registrasi->tasks->contains(function ($task) {
                return in_array((int) $task->status, [
                    RegistrasiTask::STATUS_PROSES,
                    RegistrasiTask::STATUS_SELESAI,
                ], true);
            });
        }

        return $registrasi->tasks()
            ->whereIn('status', [
                RegistrasiTask::STATUS_PROSES,
                RegistrasiTask::STATUS_SELESAI,
            ])
            ->exists();
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

    private function putIfColumn(array &$payload, string $table, string $column, $value): void
    {
        if (Schema::hasColumn($table, $column)) {
            $payload[$column] = $value;
        }
    }

    private function resolveChannelKonsultasiFromMapping(
        bool $adaKonsultasi,
        ?string $channelKonsultasi,
        ?string $sourceCode
    ): string {
        if (!$adaKonsultasi) {
            return RegistrasiKunjungan::CHANNEL_TIDAK_KONSULTASI;
        }

        if ($channelKonsultasi === 'online') {
            return RegistrasiKunjungan::CHANNEL_ONLINE;
        }

        if ($channelKonsultasi === 'offline') {
            return RegistrasiKunjungan::CHANNEL_OFFLINE;
        }

        if (str_contains(strtoupper((string) $sourceCode), 'ONLINE')) {
            return RegistrasiKunjungan::CHANNEL_ONLINE;
        }

        return RegistrasiKunjungan::CHANNEL_OFFLINE;
    }

    private function resolveTotalKonsultasi(
        bool $adaKonsultasi,
        bool $adaTreatment,
        ?MasterAccurateItemMapping $mapping
    ): float {
        if (!$adaKonsultasi || !$mapping) {
            return 0;
        }

        if ($adaTreatment) {
            return 0;
        }

        if (!$this->toBool($mapping->is_billable ?? false)) {
            return 0;
        }

        return (float) ($mapping->default_harga ?? 0);
    }

    private function resolveRuleBiayaKonsultasi(
        bool $adaKonsultasi,
        bool $adaTreatment,
        float $totalKonsultasi
    ): int {
        if (!$adaKonsultasi) {
            return 0;
        }

        if ($adaTreatment) {
            return 2;
        }

        if ($totalKonsultasi > 0) {
            return 1;
        }

        return 3;
    }

    private function resolveCatatanBiayaKonsultasi(
        bool $adaKonsultasi,
        bool $adaTreatment,
        float $totalKonsultasi,
        ?MasterAccurateItemMapping $mapping
    ): ?string {
        if (!$adaKonsultasi) {
            return null;
        }

        if ($adaTreatment) {
            return 'Biaya konsultasi Rp 0 karena konsultasi digabung dengan treatment.';
        }

        if ($totalKonsultasi > 0) {
            return 'Biaya konsultasi mengikuti master mapping: '
                . ($mapping?->source_name ?? $mapping?->source_code ?? '-');
        }

        return 'Biaya konsultasi Rp 0 sesuai master mapping.';
    }

    private function generateKodeRegistrasi($tokoId, $tanggal)
    {
        $date = Carbon::parse($tanggal)->format('Ymd');

        $count = RegistrasiKunjungan::query()
            ->where('toko_id', $tokoId)
            ->whereDate('tanggal_kunjungan', $tanggal)
            ->count() + 1;

        return 'REG-' .
            $date . '-' .
            str_pad($tokoId, 2, '0', STR_PAD_LEFT) . '-' .
            str_pad($count, 4, '0', STR_PAD_LEFT);
    }

    private function formatTimeValue($value)
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