<?php

namespace App\Http\Controllers\Api\Kasir;

use App\Http\Controllers\Controller;
use App\Models\Pembayaran\PembayaranDepositTreatmentClaim;
use App\Models\Pembayaran\PembayaranInvoice;
use App\Models\Pembayaran\PembayaranInvoiceItem;
use App\Models\Pembayaran\PembayaranInvoiceMetode;
use App\Models\Registrasi\RegistrasiKunjungan;
use App\Models\Registrasi\RegistrasiTask;
use App\Models\Stock\StockProdukToko;
use App\Models\Stock\StockReservasiProduk;
use App\Services\Stock\StockTransactionService;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Validator;

class PembayaranController extends Controller
{
    protected StockTransactionService $stockTransactionService;

    public function __construct(StockTransactionService $stockTransactionService)
    {
        $this->stockTransactionService = $stockTransactionService;
    }

    public function index(Request $request)
    {
        if ($request->boolean('sync_pending')) {
            $this->syncPendingInvoicesLite($request);
        }

        $perPage = (int) $request->get('per_page', 15);

        if ($perPage <= 0) {
            $perPage = 15;
        }

        if ($perPage > 50) {
            $perPage = 50;
        }

        $query = PembayaranInvoice::query()
            ->with([
                'registrasi.pasien',
                'registrasi.dokterAwal',
                'registrasi.perawatAwal',
                'metode' => function ($q) {
                    $q->where('is_delete', 0);
                },
            ])
            ->withCount([
                'items as items_count' => function ($q) {
                    $q->where('is_delete', 0);
                },
            ])
            ->withSum([
                'depositClaims as total_deposit_claim' => function ($q) {
                    $q->where('is_delete', 0)
                        ->where('status', PembayaranDepositTreatmentClaim::STATUS_AKTIF);
                },
            ], 'nilai_realisasi')
            ->active()
            ->whereIn('status', [
                PembayaranInvoice::STATUS_MENUNGGU,
                PembayaranInvoice::STATUS_PROSES,
                PembayaranInvoice::STATUS_LUNAS,
            ]);

        $this->applyPaymentListFilters($query, $request);

        $summaryQuery = clone $query;

        $rows = $query
            ->orderByDesc('tanggal_invoice')
            ->orderByDesc('id')
            ->paginate($perPage);

        $items = $rows->getCollection()
            ->map(fn ($invoice) => $this->formatPaymentListRow($invoice))
            ->values();

        return response()->json([
            'status' => true,
            'message' => 'Data pembayaran berhasil diambil',
            'rows' => $items,
            'total' => $rows->total(),
            'per_page' => $rows->perPage(),
            'current_page' => $rows->currentPage(),
            'last_page' => $rows->lastPage(),
            'from' => $rows->firstItem(),
            'to' => $rows->lastItem(),
            'summary' => $this->buildSummaryFast($summaryQuery),
        ]);
    }

    public function show($id)
    {
        $invoice = $this->resolveInvoice($id);

        if (!$invoice) {
            return response()->json([
                'status' => false,
                'message' => 'Invoice tidak ditemukan',
            ], 404);
        }

        $invoice->load([
            'registrasi.toko',
            'registrasi.pasien',
            'registrasi.dokterAwal',
            'registrasi.perawatAwal',
            'registrasi.tasks' => function ($q) {
                $q->orderBy('task_order');
            },
            'items.promos',
            'items.depositClaims',
            'metode',
            'promos',
            'depositClaims.depositTreatment',
        ]);

        return response()->json([
            'status' => true,
            'message' => 'Detail pembayaran berhasil diambil',
            'data' => [
                'invoice' => $this->formatPaymentRow($invoice),
                'items' => $invoice->items->where('is_delete', 0)->values(),
                'metode' => $invoice->metode->where('is_delete', 0)->values(),
                'promo' => $invoice->promos->where('is_delete', 0)->values(),
                'deposit_claims' => $invoice->depositClaims->where('is_delete', 0)->values(),
            ],
        ]);
    }

    public function generate($registrasiId)
    {
        $registrasi = RegistrasiKunjungan::query()
            ->with([
                'pasien',
                'tasks',
                'treatmentDetails',
                'penjualanDetails',
            ])
            ->active()
            ->findOrFail($registrasiId);

        DB::beginTransaction();

        try {
            $invoice = $this->generateInvoiceFromRegistrasi($registrasi, true);

            DB::commit();

            return response()->json([
                'status' => true,
                'message' => 'Invoice berhasil dibuat / disinkronkan',
                'data' => $this->formatPaymentRow(
                    $invoice->fresh([
                        'registrasi.pasien',
                        'registrasi.dokterAwal',
                        'registrasi.perawatAwal',
                        'registrasi.tasks',
                        'items',
                        'metode',
                        'promos',
                        'depositClaims',
                    ])
                ),
            ]);
        } catch (\Throwable $e) {
            DB::rollBack();

            return response()->json([
                'status' => false,
                'message' => 'Gagal membuat invoice',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    public function start($id)
    {
        $invoice = $this->resolveOrGenerateInvoice($id);

        if (!$invoice) {
            return response()->json([
                'status' => false,
                'message' => 'Invoice tidak ditemukan atau belum bisa dibuat dari registrasi',
            ], 404);
        }

        if ((int) $invoice->status === PembayaranInvoice::STATUS_LUNAS) {
            return response()->json([
                'status' => false,
                'message' => 'Invoice sudah lunas',
            ], 422);
        }

        $registrasi = $invoice->registrasi()->with('tasks')->first();

        if (!$registrasi) {
            return response()->json([
                'status' => false,
                'message' => 'Data registrasi invoice tidak ditemukan',
            ], 422);
        }

        $task = $this->getPaymentTask($registrasi);

        DB::beginTransaction();

        try {
            $invoice->update($this->onlyExistingColumns('pembayaran_invoice', [
                'status' => PembayaranInvoice::STATUS_PROSES,
                'updated_by' => $this->username(),
                'updated_at' => now(),
            ]));

            if ($task && (int) $task->status === RegistrasiTask::STATUS_MENUNGGU) {
                $task->update([
                    'status' => RegistrasiTask::STATUS_PROSES,
                    'started_at' => now(),
                    'updated_by' => $this->username(),
                    'updated_at' => now(),
                ]);
            }

            $registrasi->update([
                'current_task' => RegistrasiTask::TYPE_PEMBAYARAN,
                'updated_by' => $this->username(),
                'updated_at' => now(),
            ]);

            DB::commit();

            return response()->json([
                'status' => true,
                'message' => 'Pembayaran berhasil diproses',
                'data' => $this->formatPaymentRow($invoice->fresh([
                    'registrasi.pasien',
                    'registrasi.dokterAwal',
                    'registrasi.perawatAwal',
                    'registrasi.tasks',
                    'items',
                    'metode',
                    'promos',
                    'depositClaims',
                ])),
            ]);
        } catch (\Throwable $e) {
            DB::rollBack();

            return response()->json([
                'status' => false,
                'message' => 'Gagal memproses pembayaran',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    public function finish(Request $request, $id)
    {
        $validator = Validator::make($request->all(), [
            'metode' => 'nullable|array',
            'metode.*.metode_bayar_id' => 'nullable|integer',
            'metode.*.metode_bayar_nama' => 'required_with:metode|string|max:100',
            'metode.*.metode_bayar_tipe' => 'nullable|integer',
            'metode.*.nominal_dialokasikan' => 'required_with:metode|numeric|min:0',
            'metode.*.nominal_diterima' => 'nullable|numeric|min:0',
            'metode.*.no_referensi' => 'nullable|string|max:150',
            'metode.*.catatan' => 'nullable|string',
            'metode_pembayaran' => 'nullable|string|max:100',
            'jumlah_bayar' => 'nullable|numeric|min:0',
            'catatan_pembayaran' => 'nullable|string',
            'jenis_transaksi' => 'nullable|integer|in:0,1,2,3,4',
            'sumber_informasi_id' => 'nullable|integer',
            'sumber_kedatangan' => 'nullable|string|max:100',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status' => false,
                'message' => 'Validasi gagal',
                'errors' => $validator->errors(),
            ], 422);
        }

        $invoice = $this->resolveInvoice($id);

        if (!$invoice) {
            return response()->json([
                'status' => false,
                'message' => 'Invoice tidak ditemukan',
            ], 404);
        }

        if ((int) $invoice->status === PembayaranInvoice::STATUS_LUNAS) {
            return response()->json([
                'status' => false,
                'message' => 'Invoice sudah lunas',
            ], 422);
        }

        $registrasi = $invoice->registrasi()->with('tasks')->first();

        if (!$registrasi) {
            return response()->json([
                'status' => false,
                'message' => 'Data registrasi invoice tidak ditemukan',
            ], 422);
        }

        $metode = $this->normalizeMetodePayload($request, $invoice);
        $totalBayar = collect($metode)->sum('nominal_dialokasikan');
        $grandTotal = (float) ($request->input('grand_total', $invoice->grand_total) ?: 0);

        if ($totalBayar < $grandTotal) {
            return response()->json([
                'status' => false,
                'message' => 'Jumlah bayar kurang dari grand total',
                'data' => [
                    'grand_total' => $grandTotal,
                    'total_bayar' => $totalBayar,
                    'kurang' => $grandTotal - $totalBayar,
                ],
            ], 422);
        }

        DB::beginTransaction();

        try {
            $this->replaceInvoiceMetode($invoice, $metode);
            $this->processStockKeluarPembayaran($invoice, $registrasi);

            $invoice->update($this->onlyExistingColumns('pembayaran_invoice', [
                'tanggal_lunas' => now(),
                'jenis_transaksi' => (int) $request->input('jenis_transaksi', $invoice->jenis_transaksi ?? 0),
                'sumber_informasi_id' => $request->input('sumber_informasi_id', $invoice->sumber_informasi_id ?? null),
                'sumber_kedatangan' => $request->input('sumber_kedatangan', $invoice->sumber_kedatangan ?? null),
                'catatan' => $request->input('catatan_pembayaran', $invoice->catatan ?? null),
                'subtotal_obat' => $request->input('subtotal_obat', $invoice->subtotal_obat ?? 0),
                'subtotal_treatment' => $request->input('subtotal_treatment', $invoice->subtotal_treatment ?? 0),
                'subtotal' => $request->input('subtotal', $invoice->subtotal ?? 0),
                'diskon_subtotal' => $request->input('diskon_subtotal', $invoice->diskon_subtotal ?? 0),
                'diskon_promo' => $request->input('diskon_promo', $invoice->diskon_promo ?? 0),
                'grand_total' => $grandTotal,
                'total_bayar' => $totalBayar,
                'sisa_tagihan' => 0,
                'total_kembalian' => max(0, $totalBayar - $grandTotal),
                'status' => PembayaranInvoice::STATUS_LUNAS,
                'updated_by' => $this->username(),
                'updated_at' => now(),
            ]));

            $paymentTask = $this->getPaymentTask($registrasi);

            if ($paymentTask) {
                $paymentTask->update([
                    'status' => RegistrasiTask::STATUS_SELESAI,
                    'started_at' => $paymentTask->started_at ?: now(),
                    'finished_at' => now(),
                    'updated_by' => $this->username(),
                    'updated_at' => now(),
                ]);
            }

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
                    'current_task' => RegistrasiKunjungan::TASK_DRAFT,
                    'status' => RegistrasiKunjungan::STATUS_SELESAI,
                    'updated_by' => $this->username(),
                    'updated_at' => now(),
                ]);
            }

            DB::commit();

            return response()->json([
                'status' => true,
                'message' => $nextTask
                    ? 'Pembayaran berhasil. Registrasi dilanjutkan ke task berikutnya.'
                    : 'Pembayaran berhasil. Registrasi selesai.',
                'data' => $this->formatPaymentRow($invoice->fresh([
                    'registrasi.pasien',
                    'registrasi.dokterAwal',
                    'registrasi.perawatAwal',
                    'registrasi.tasks',
                    'items',
                    'metode',
                    'promos',
                    'depositClaims',
                ])),
            ]);
        } catch (\Throwable $e) {
            DB::rollBack();

            return response()->json([
                'status' => false,
                'message' => 'Gagal menyelesaikan pembayaran',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    public function recalculate($id)
    {
        $invoice = $this->resolveInvoice($id);

        if (!$invoice) {
            return response()->json([
                'status' => false,
                'message' => 'Invoice tidak ditemukan',
            ], 404);
        }

        DB::beginTransaction();

        try {
            $this->recalculateInvoiceTotals($invoice);

            DB::commit();

            return response()->json([
                'status' => true,
                'message' => 'Invoice berhasil dihitung ulang',
                'data' => $this->formatPaymentRow($invoice->fresh([
                    'registrasi.pasien',
                    'registrasi.dokterAwal',
                    'registrasi.perawatAwal',
                    'registrasi.tasks',
                    'items',
                    'metode',
                    'promos',
                    'depositClaims',
                ])),
            ]);
        } catch (\Throwable $e) {
            DB::rollBack();

            return response()->json([
                'status' => false,
                'message' => 'Gagal menghitung ulang invoice',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    public function cancel($id)
    {
        $invoice = $this->resolveInvoice($id);

        if (!$invoice) {
            return response()->json([
                'status' => false,
                'message' => 'Invoice tidak ditemukan',
            ], 404);
        }

        if ((int) $invoice->status === PembayaranInvoice::STATUS_LUNAS) {
            return response()->json([
                'status' => false,
                'message' => 'Invoice lunas tidak bisa dibatalkan',
            ], 422);
        }

        DB::beginTransaction();

        try {
            $invoice->update($this->onlyExistingColumns('pembayaran_invoice', [
                'status' => PembayaranInvoice::STATUS_BATAL ?? 9,
                'is_delete' => 1,
                'deleted_by' => $this->username(),
                'deleted_at' => now(),
                'updated_by' => $this->username(),
                'updated_at' => now(),
            ]));

            DB::commit();

            return response()->json([
                'status' => true,
                'message' => 'Invoice berhasil dibatalkan',
            ]);
        } catch (\Throwable $e) {
            DB::rollBack();

            return response()->json([
                'status' => false,
                'message' => 'Gagal membatalkan invoice',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    protected function applyPaymentListFilters($query, Request $request): void
    {
        if ($request->filled('toko_id')) {
            $query->where('toko_id', $request->toko_id);
        }

        if ($request->filled('tanggal')) {
            $query->whereDate('tanggal_invoice', $request->tanggal);
        }

        if ($request->filled('tanggal_mulai')) {
            $query->whereDate('tanggal_invoice', '>=', $request->tanggal_mulai);
        }

        if ($request->filled('tanggal_selesai')) {
            $query->whereDate('tanggal_invoice', '<=', $request->tanggal_selesai);
        }

        if ($request->filled('status')) {
            $status = $this->mapRequestStatusToInvoiceStatus($request->status);

            if ($status !== null) {
                $query->where('status', $status);
            }
        }

        if ($request->filled('channel')) {
            $this->applyChannelFilter($query, $request->channel);
        }

        if ($request->filled('search')) {
            $search = trim((string) $request->search);

            $query->where(function ($q) use ($search) {
                $q->where('no_invoice', 'like', "%{$search}%")
                    ->orWhere('kode_registrasi', 'like', "%{$search}%")
                    ->orWhereHas('registrasi.pasien', function ($p) use ($search) {
                        $p->where('nama', 'like', "%{$search}%")
                            ->orWhere('no_rm', 'like', "%{$search}%")
                            ->orWhere('no_hp', 'like', "%{$search}%")
                            ->orWhere('no_wa', 'like', "%{$search}%");
                    })
                    ->orWhereHas('registrasi.dokterAwal', function ($d) use ($search) {
                        $d->where('nama', 'like', "%{$search}%");
                    });
            });
        }
    }

    protected function applyChannelFilter($query, string $channel): void
    {
        $channel = strtolower(trim($channel));

        $query->whereHas('registrasi', function ($q) use ($channel) {
            if ($channel === 'online') {
                $q->where(function ($sub) {
                    $sub->where('channel_konsultasi', 'online')
                        ->orWhere('is_konsultasi_online', 1)
                        ->orWhere('is_pembelian_online', 1);
                });
            } elseif ($channel === 'offline') {
                $q->where(function ($sub) {
                    $sub->whereNull('channel_konsultasi')
                        ->orWhere('channel_konsultasi', '<>', 'online');
                })
                    ->where(function ($sub) {
                        $sub->whereNull('is_konsultasi_online')
                            ->orWhere('is_konsultasi_online', 0);
                    })
                    ->where(function ($sub) {
                        $sub->whereNull('is_pembelian_online')
                            ->orWhere('is_pembelian_online', 0);
                    });
            }
        });
    }

    protected function buildSummaryFast($query): array
    {
        $summaryQuery = clone $query;

        /*
         * Query index memakai eager load, withCount(), dan withSum() untuk kebutuhan tabel.
         * Query tersebut tidak boleh langsung dipakai untuk summary aggregate, karena MySQL
         * strict mode akan membaca pembayaran_invoice.* + COUNT/SUM tanpa GROUP BY dan memicu error 1140.
         * Di sini kolom select dan binding select dari query tabel dibersihkan, lalu dipakai ulang
         * hanya kondisi filter-nya saja.
         */
        $baseQuery = $summaryQuery->getQuery();
        $baseQuery->columns = null;
        $baseQuery->orders = null;
        $baseQuery->limit = null;
        $baseQuery->offset = null;
        $baseQuery->bindings['select'] = [];
        $baseQuery->bindings['order'] = [];

        $summaryQuery->setEagerLoads([]);

        $summary = $summaryQuery
            ->selectRaw('
                COUNT(*) as total_invoice,
                SUM(CASE WHEN pembayaran_invoice.status = ? THEN 1 ELSE 0 END) as total_menunggu,
                SUM(CASE WHEN pembayaran_invoice.status = ? THEN 1 ELSE 0 END) as total_diproses,
                SUM(CASE WHEN pembayaran_invoice.status = ? THEN 1 ELSE 0 END) as total_lunas,
                SUM(CASE WHEN pembayaran_invoice.status <> ? THEN 1 ELSE 0 END) as total_belum_lunas,
                SUM(COALESCE(pembayaran_invoice.grand_total, 0)) as grand_total,
                SUM(COALESCE(pembayaran_invoice.total_bayar, 0)) as total_bayar
            ', [
                PembayaranInvoice::STATUS_MENUNGGU,
                PembayaranInvoice::STATUS_PROSES,
                PembayaranInvoice::STATUS_LUNAS,
                PembayaranInvoice::STATUS_LUNAS,
            ])
            ->first();

        $totalInvoice = (int) ($summary->total_invoice ?? 0);
        $totalMenunggu = (int) ($summary->total_menunggu ?? 0);
        $totalDiproses = (int) ($summary->total_diproses ?? 0);
        $totalLunas = (int) ($summary->total_lunas ?? 0);
        $totalBelumLunas = (int) ($summary->total_belum_lunas ?? 0);
        $grandTotal = (float) ($summary->grand_total ?? 0);
        $totalBayar = (float) ($summary->total_bayar ?? 0);

        return [
            // Key utama yang dibaca all-pembayaran.vue
            'total' => $totalInvoice,
            'menunggu' => $totalMenunggu,
            'diproses' => $totalDiproses,
            'lunas' => $totalLunas,

            // Alias untuk kompatibilitas response lama / debug
            'total_invoice' => $totalInvoice,
            'total_menunggu' => $totalMenunggu,
            'total_diproses' => $totalDiproses,
            'total_lunas' => $totalLunas,
            'total_belum_lunas' => $totalBelumLunas,
            'grand_total' => $grandTotal,
            'total_bayar' => $totalBayar,
        ];
    }

    protected function formatPaymentListRow(PembayaranInvoice $invoice): array
    {
        $registrasi = $invoice->registrasi;
        $pasien = $registrasi?->pasien;
        $statusKey = $this->mapInvoiceStatusToKey($invoice->status);
        $statusLabel = $this->mapInvoiceStatusToLabel($invoice->status);
        $grandTotal = (float) ($invoice->grand_total ?? 0);
        $totalBayar = (float) ($invoice->total_bayar ?? 0);
        $metodePembayaran = $this->formatMetodePembayaranList($invoice);
        $layananLabel = $this->buildLayananLabelForInvoice($invoice);
        $tanggalKunjungan = $registrasi?->tanggal_kunjungan
            ?? $registrasi?->tanggal
            ?? $invoice->tanggal_invoice;

        return [
            'id' => $invoice->id,
            'invoice_id' => $invoice->id,
            'pembayaran_id' => $invoice->id,
            'registrasi_id' => $invoice->registrasi_id,

            // Alias invoice yang dibaca frontend
            'no_invoice' => $invoice->no_invoice,
            'nomor_invoice' => $invoice->no_invoice,
            'invoice_number' => $invoice->no_invoice,
            'kode_registrasi' => $invoice->kode_registrasi,
            'nomor_kunjungan' => $invoice->kode_registrasi,

            'tanggal_invoice' => $invoice->tanggal_invoice,
            'tanggal_lunas' => $invoice->tanggal_lunas,
            'tanggal_kunjungan' => $tanggalKunjungan,
            'tanggal' => $tanggalKunjungan,
            'registered_at' => $registrasi?->registered_at ?? null,
            'created_at' => $invoice->created_at,
            'toko_id' => $invoice->toko_id,

            'pasien_id' => $pasien?->id,
            'pasien_nama' => $pasien?->nama ?? $invoice->nama_pasien ?? '-',
            'nama_pasien' => $pasien?->nama ?? $invoice->nama_pasien ?? '-',
            'no_rm' => $pasien?->no_rm,
            'pasien_no_rm' => $pasien?->no_rm,
            'no_hp' => $pasien?->no_hp ?? $pasien?->no_wa ?? null,
            'pasien_no_hp' => $pasien?->no_hp ?? $pasien?->no_wa ?? null,
            'pasien' => $pasien ? [
                'id' => $pasien->id,
                'nama' => $pasien->nama,
                'no_rm' => $pasien->no_rm ?? null,
                'no_hp' => $pasien->no_hp ?? $pasien->no_wa ?? null,
            ] : null,

            'dokter_nama' => $registrasi?->dokterAwal?->nama,
            'perawat_nama' => $registrasi?->perawatAwal?->nama,
            'items_count' => (int) ($invoice->items_count ?? 0),

            'subtotal_obat' => (float) ($invoice->subtotal_obat ?? 0),
            'subtotal_treatment' => (float) ($invoice->subtotal_treatment ?? 0),
            'total_tagihan' => $grandTotal,
            'grand_total' => $grandTotal,
            'total_pembayaran' => $grandTotal,
            'total_bayar' => $totalBayar,
            'sisa_tagihan' => max($grandTotal - $totalBayar, 0),

            // Status numeric tetap dikirim untuk kompatibilitas backend,
            // tetapi frontend all-pembayaran.vue membaca status_pembayaran_key lebih dulu.
            'status' => (int) $invoice->status,
            'status_key' => $statusKey,
            'status_label' => $statusLabel,
            'status_pembayaran_key' => $statusKey,
            'status_pembayaran' => $statusLabel,
            'status_pembayaran_label' => $statusLabel,

            'metode' => $invoice->metode?->where('is_delete', 0)->values() ?? [],
            'metode_pembayaran' => $metodePembayaran,

            'jenis_transaksi' => (int) ($invoice->jenis_transaksi ?? 0),
            'sumber_informasi_id' => $invoice->sumber_informasi_id ?? null,
            'sumber_kedatangan' => $invoice->sumber_kedatangan ?? null,

            'channel_konsultasi' => $registrasi?->channel_konsultasi ?? null,
            'ada_konsultasi' => $this->hasRegistrasiConsultation($registrasi),
            'ada_treatment' => (float) ($invoice->subtotal_treatment ?? 0) > 0,
            'ada_penjualan' => (float) ($invoice->subtotal_obat ?? 0) > 0,
            'is_treatment' => (float) ($invoice->subtotal_treatment ?? 0) > 0,
            'is_penjualan' => (float) ($invoice->subtotal_obat ?? 0) > 0,
            'layanan_label' => $layananLabel,
            'can_process_pembayaran' => in_array($statusKey, ['menunggu', 'proses'], true),
        ];
    }

    protected function formatPaymentRow(PembayaranInvoice $invoice): array
    {
        $registrasi = $invoice->registrasi;
        $pasien = $registrasi?->pasien;

        return [
            'id' => $invoice->id,
            'invoice_id' => $invoice->id,
            'registrasi_id' => $invoice->registrasi_id,
            'no_invoice' => $invoice->no_invoice,
            'kode_registrasi' => $invoice->kode_registrasi,
            'tanggal_invoice' => $invoice->tanggal_invoice,
            'tanggal_lunas' => $invoice->tanggal_lunas,
            'toko_id' => $invoice->toko_id,
            'pasien' => $pasien,
            'dokter_awal' => $registrasi?->dokterAwal,
            'perawat_awal' => $registrasi?->perawatAwal,
            'items' => $invoice->items?->where('is_delete', 0)->values() ?? [],
            'metode' => $invoice->metode?->where('is_delete', 0)->values() ?? [],
            'promo' => $invoice->promos?->where('is_delete', 0)->values() ?? [],
            'deposit_claims' => $invoice->depositClaims?->where('is_delete', 0)->values() ?? [],
            'subtotal_obat' => (float) ($invoice->subtotal_obat ?? 0),
            'subtotal_treatment' => (float) ($invoice->subtotal_treatment ?? 0),
            'subtotal' => (float) ($invoice->subtotal ?? 0),
            'diskon_subtotal' => (float) ($invoice->diskon_subtotal ?? 0),
            'diskon_promo' => (float) ($invoice->diskon_promo ?? 0),
            'grand_total' => (float) ($invoice->grand_total ?? 0),
            'total_bayar' => (float) ($invoice->total_bayar ?? 0),
            'sisa_tagihan' => max((float) ($invoice->grand_total ?? 0) - (float) ($invoice->total_bayar ?? 0), 0),
            'total_kembalian' => (float) ($invoice->total_kembalian ?? 0),
            'status' => (int) $invoice->status,
            'status_key' => $this->mapInvoiceStatusToKey($invoice->status),
            'status_label' => $this->mapInvoiceStatusToLabel($invoice->status),
            'jenis_transaksi' => (int) ($invoice->jenis_transaksi ?? 0),
            'sumber_informasi_id' => $invoice->sumber_informasi_id ?? null,
            'sumber_kedatangan' => $invoice->sumber_kedatangan ?? null,
            'catatan' => $invoice->catatan ?? null,
        ];
    }

    protected function mapRequestStatusToInvoiceStatus($status): ?int
    {
        return match (strtolower((string) $status)) {
            'menunggu', 'waiting', 'belum_lunas' => PembayaranInvoice::STATUS_MENUNGGU,
            'proses', 'process' => PembayaranInvoice::STATUS_PROSES,
            'lunas', 'paid' => PembayaranInvoice::STATUS_LUNAS,
            default => is_numeric($status) ? (int) $status : null,
        };
    }

    protected function mapInvoiceStatusToKey($status): string
    {
        return match ((int) $status) {
            PembayaranInvoice::STATUS_MENUNGGU => 'menunggu',
            PembayaranInvoice::STATUS_PROSES => 'proses',
            PembayaranInvoice::STATUS_LUNAS => 'lunas',
            default => 'unknown',
        };
    }

    protected function mapInvoiceStatusToLabel($status): string
    {
        return match ((int) $status) {
            PembayaranInvoice::STATUS_MENUNGGU => 'Menunggu Pembayaran',
            PembayaranInvoice::STATUS_PROSES => 'Diproses',
            PembayaranInvoice::STATUS_LUNAS => 'Lunas',
            default => '-',
        };
    }

    protected function formatMetodePembayaranList(PembayaranInvoice $invoice): ?string
    {
        $metode = $invoice->metode?->where('is_delete', 0)->values() ?? collect();

        if ($metode->isEmpty()) {
            return null;
        }

        return $metode
            ->map(function ($item) {
                return $item->metode_bayar_nama
                    ?? $item->nama_metode_bayar
                    ?? $item->nama
                    ?? null;
            })
            ->filter()
            ->unique()
            ->values()
            ->implode(', ');
    }

    protected function hasRegistrasiConsultation(?RegistrasiKunjungan $registrasi): bool
    {
        if (!$registrasi) {
            return false;
        }

        $channel = strtolower((string) ($registrasi->channel_konsultasi ?? ''));

        return in_array($channel, ['offline', 'online', 'sppg', 'spkk', '1', '2'], true)
            || (int) ($registrasi->is_konsultasi ?? 0) === 1
            || (int) ($registrasi->is_konsultasi_online ?? 0) === 1
            || (float) ($registrasi->total_konsultasi ?? 0) > 0;
    }

    protected function buildLayananLabelForInvoice(PembayaranInvoice $invoice): string
    {
        $hasConsultation = $this->hasRegistrasiConsultation($invoice->registrasi);
        $hasTreatment = (float) ($invoice->subtotal_treatment ?? 0) > 0;
        $hasSales = (float) ($invoice->subtotal_obat ?? 0) > 0;

        if ($hasConsultation && $hasTreatment && $hasSales) {
            return 'Konsultasi + Treatment + Penjualan';
        }

        if ($hasConsultation && $hasTreatment) {
            return 'Konsultasi + Treatment';
        }

        if ($hasConsultation && $hasSales) {
            return 'Konsultasi + Penjualan';
        }

        if ($hasTreatment && $hasSales) {
            return 'Treatment + Penjualan';
        }

        if ($hasConsultation) {
            return 'Konsultasi';
        }

        if ($hasTreatment) {
            return 'Treatment';
        }

        if ($hasSales) {
            return 'Penjualan Produk';
        }

        return '-';
    }

    protected function resolveOrGenerateInvoice($id): ?PembayaranInvoice
    {
        $invoice = $this->resolveInvoice($id);

        if ($invoice) {
            return $invoice;
        }

        $registrasi = RegistrasiKunjungan::query()
            ->with([
                'pasien',
                'tasks',
                'treatmentDetails',
                'penjualanDetails',
            ])
            ->active()
            ->find($id);

        if (!$registrasi) {
            return null;
        }

        try {
            DB::beginTransaction();

            $invoice = $this->generateInvoiceFromRegistrasi($registrasi, true);

            DB::commit();

            return $this->resolveInvoice($invoice->id);
        } catch (\Throwable $e) {
            DB::rollBack();
            Log::error('Gagal membuat invoice otomatis saat finish pembayaran', [
                'registrasi_id' => $id,
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }

    protected function resolveInvoice($id): ?PembayaranInvoice
    {
        return PembayaranInvoice::query()
            ->with([
                'registrasi.pasien',
                'registrasi.dokterAwal',
                'registrasi.perawatAwal',
                'items',
                'metode',
                'promos',
                'depositClaims',
            ])
            ->where(function ($q) use ($id) {
                $q->where('id', $id)
                    ->orWhere('registrasi_id', $id);
            })
            ->where(function ($q) {
                $q->whereNull('is_delete')
                    ->orWhere('is_delete', 0);
            })
            ->orderByDesc('id')
            ->first();
    }

    protected function normalizeMetodePayload(Request $request, PembayaranInvoice $invoice): array
    {
        $metode = $request->input('metode', []);

        if (!is_array($metode) || count($metode) === 0) {
            $jumlahBayar = (float) $request->input('jumlah_bayar', $invoice->grand_total ?? 0);
            $nama = $request->input('metode_pembayaran', 'CASH');

            $metode = [[
                'metode_bayar_id' => null,
                'metode_bayar_nama' => $nama,
                'metode_bayar_tipe' => 1,
                'nominal_dialokasikan' => $jumlahBayar,
                'nominal_diterima' => $jumlahBayar,
                'no_referensi' => null,
                'catatan' => null,
            ]];
        }

        return collect($metode)
            ->map(function ($item) {
                $dialokasikan = (float) ($item['nominal_dialokasikan'] ?? $item['nominal'] ?? 0);
                $diterima = (float) ($item['nominal_diterima'] ?? $dialokasikan);
                $tipe = $item['metode_bayar_tipe'] ?? 1;

                if ($tipe === null || $tipe === '') {
                    $tipe = 1;
                }

                return [
                    'metode_bayar_id' => $item['metode_bayar_id'] ?? null,
                    'metode_bayar_nama' => $item['metode_bayar_nama'] ?? 'CASH',
                    'metode_bayar_tipe' => (int) $tipe,
                    'nominal_dialokasikan' => $dialokasikan,
                    'nominal_diterima' => $diterima,
                    'nominal_kembalian' => max(0, $diterima - $dialokasikan),
                    'no_referensi' => $item['no_referensi'] ?? null,
                    'catatan' => $item['catatan'] ?? null,
                ];
            })
            ->filter(fn ($item) => (float) $item['nominal_dialokasikan'] > 0)
            ->values()
            ->all();
    }

    protected function replaceInvoiceMetode(PembayaranInvoice $invoice, array $metode): void
    {
        $invoice->metode()->update($this->onlyExistingColumns('pembayaran_invoice_metode', [
            'is_delete' => 1,
            'deleted_by' => $this->username(),
            'deleted_at' => now(),
            'updated_by' => $this->username(),
            'updated_at' => now(),
        ]));

        foreach ($metode as $index => $item) {
            PembayaranInvoiceMetode::query()->create($this->onlyExistingColumns('pembayaran_invoice_metode', [
                'pembayaran_id' => $invoice->id,
                'pembayaran_invoice_id' => $invoice->id,
                'invoice_id' => $invoice->id,
                'toko_id' => $invoice->toko_id,
                'metode_bayar_id' => $item['metode_bayar_id'],
                'metode_bayar_nama' => $item['metode_bayar_nama'],
                'metode_bayar_tipe' => (int) ($item['metode_bayar_tipe'] ?: 1),
                'nominal_dialokasikan' => $item['nominal_dialokasikan'],
                'nominal_diterima' => $item['nominal_diterima'],
                'nominal_kembalian' => $item['nominal_kembalian'] ?? max(0, (float) $item['nominal_diterima'] - (float) $item['nominal_dialokasikan']),
                'no_referensi' => $item['no_referensi'],
                'catatan' => $item['catatan'],
                'sort_order' => $index + 1,
                'status' => 1,
                'is_delete' => 0,
                'created_by' => $this->username(),
                'updated_by' => $this->username(),
                'created_at' => now(),
                'updated_at' => now(),
            ]));
        }
    }

    protected function processStockKeluarPembayaran(PembayaranInvoice $invoice, RegistrasiKunjungan $registrasi): void
    {
        if (!method_exists($this->stockTransactionService, 'keluarPembayaran')) {
            return;
        }

        $items = $invoice->items()
            ->where(function ($q) {
                $q->whereNull('is_delete')->orWhere('is_delete', 0);
            })
            ->where(function ($q) {
                $q->where('item_type', 'produk')->orWhere('jenis_item', 'produk');
            })
            ->get();

        foreach ($items as $item) {
            if (empty($item->produk_toko_id) || (float) ($item->qty ?? $item->jumlah ?? 0) <= 0) {
                continue;
            }

            $this->stockTransactionService->keluarPembayaran([
                'toko_id' => $invoice->toko_id,
                'produk_toko_id' => $item->produk_toko_id,
                'jumlah' => (float) ($item->qty ?? $item->jumlah ?? 0),
                'referensi_id' => $invoice->id,
                'referensi_tipe' => 'pembayaran_invoice',
                'catatan' => 'Stock keluar pembayaran ' . $invoice->no_invoice,
            ]);
        }
    }

    protected function getPaymentTask(RegistrasiKunjungan $registrasi): ?RegistrasiTask
    {
        return $registrasi->tasks
            ? $registrasi->tasks->firstWhere('task_type', RegistrasiTask::TYPE_PEMBAYARAN)
            : $registrasi->tasks()
                ->where('task_type', RegistrasiTask::TYPE_PEMBAYARAN)
                ->first();
    }

    protected function syncPendingInvoicesLite(Request $request): void
    {
        $query = RegistrasiKunjungan::query()
            ->active()
            ->whereHas('tasks', function ($q) {
                $q->where('task_type', RegistrasiTask::TYPE_PEMBAYARAN);
            });

        if ($request->filled('toko_id')) {
            $query->where('toko_id', $request->toko_id);
        }

        if ($request->filled('tanggal')) {
            $query->whereDate('tanggal', $request->tanggal);
        }

        $query->limit(50)->get()->each(function ($registrasi) {
            if (!$this->resolveInvoice($registrasi->id)) {
                $this->generateInvoiceFromRegistrasi($registrasi, false);
            }
        });
    }

    protected function generateInvoiceFromRegistrasi(RegistrasiKunjungan $registrasi, bool $force = false): PembayaranInvoice
    {
        $existing = PembayaranInvoice::query()
            ->where('registrasi_id', $registrasi->id)
            ->where(function ($q) {
                $q->whereNull('is_delete')->orWhere('is_delete', 0);
            })
            ->first();

        if ($existing && !$force) {
            return $existing;
        }

        if (!$existing) {
            $existing = new PembayaranInvoice();
        }

        $noInvoice = $existing->no_invoice ?: $this->generateInvoiceNumber($registrasi);

        $existing->fill($this->onlyExistingColumns('pembayaran_invoice', [
            'registrasi_id' => $registrasi->id,
            'kode_registrasi' => $registrasi->kode_registrasi ?? null,
            'no_invoice' => $noInvoice,
            'tanggal_invoice' => $registrasi->tanggal ?? Carbon::today()->toDateString(),
            'toko_id' => $registrasi->toko_id,
            'jenis_transaksi' => 0,
            'subtotal_obat' => 0,
            'subtotal_treatment' => 0,
            'subtotal' => 0,
            'diskon_subtotal' => 0,
            'diskon_promo' => 0,
            'grand_total' => 0,
            'total_bayar' => 0,
            'sisa_tagihan' => 0,
            'status' => PembayaranInvoice::STATUS_MENUNGGU,
            'is_delete' => 0,
            'created_by' => $this->username(),
            'updated_by' => $this->username(),
            'created_at' => now(),
            'updated_at' => now(),
        ]));

        $existing->save();

        return $existing;
    }

    protected function generateInvoiceNumber(RegistrasiKunjungan $registrasi): string
    {
        $date = Carbon::parse($registrasi->tanggal ?? now())->format('Ymd');
        $seq = str_pad((string) ($registrasi->id ?? 1), 5, '0', STR_PAD_LEFT);

        return 'INV-' . $date . '-' . $seq;
    }

    protected function recalculateInvoiceTotals(PembayaranInvoice $invoice): void
    {
        $items = $invoice->items()
            ->where(function ($q) {
                $q->whereNull('is_delete')->orWhere('is_delete', 0);
            })
            ->get();

        $subtotalObat = 0;
        $subtotalTreatment = 0;

        foreach ($items as $item) {
            $amount = (float) ($item->total ?? $item->subtotal ?? 0);
            $type = strtolower((string) ($item->item_type ?? $item->jenis_item ?? ''));

            if ($type === 'produk' || $type === 'obat') {
                $subtotalObat += $amount;
            } else {
                $subtotalTreatment += $amount;
            }
        }

        $subtotal = $subtotalObat + $subtotalTreatment;
        $grandTotal = max($subtotal - (float) ($invoice->diskon_subtotal ?? 0) - (float) ($invoice->diskon_promo ?? 0), 0);
        $totalBayar = (float) ($invoice->total_bayar ?? 0);

        $invoice->update($this->onlyExistingColumns('pembayaran_invoice', [
            'subtotal_obat' => $subtotalObat,
            'subtotal_treatment' => $subtotalTreatment,
            'subtotal' => $subtotal,
            'grand_total' => $grandTotal,
            'sisa_tagihan' => max($grandTotal - $totalBayar, 0),
            'updated_by' => $this->username(),
            'updated_at' => now(),
        ]));
    }

    protected function onlyExistingColumns(string $table, array $data): array
    {
        return collect($data)
            ->filter(fn ($value, $key) => Schema::hasColumn($table, $key))
            ->all();
    }

    protected function username(): string
    {
        return auth()->user()->username
            ?? auth()->user()->name
            ?? 'system';
    }
}
