<?php

namespace App\Http\Controllers\Api\Kasir;

use App\Http\Controllers\Controller;
use App\Models\Pembayaran\PembayaranDepositTreatment;
use App\Models\Pembayaran\PembayaranDepositTreatmentClaim;
use App\Models\Pembayaran\PembayaranInvoice;
use App\Models\Pembayaran\PembayaranInvoiceItem;
use App\Models\Pembayaran\PembayaranInvoiceMetode;
use App\Models\Pembayaran\PembayaranInvoicePromo;
use App\Models\Registrasi\RegistrasiKunjungan;
use App\Models\Registrasi\RegistrasiTask;
use App\Models\Stock\StockReservasiProduk;
use App\Services\Stock\StockTransactionService;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
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
        $this->syncPendingInvoices($request);

        $query = PembayaranInvoice::query()
            ->with([
                'registrasi.toko',
                'registrasi.pasien',
                'registrasi.dokterAwal',
                'registrasi.perawatAwal',
                'registrasi.tasks' => function ($q) {
                    $q->orderBy('task_order');
                },
                'items',
                'metode',
                'promos',
                'depositClaims',
            ])
            ->active()
            ->whereIn('status', [
                PembayaranInvoice::STATUS_MENUNGGU,
                PembayaranInvoice::STATUS_PROSES,
                PembayaranInvoice::STATUS_LUNAS,
            ]);

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
            $search = trim($request->search);

            $query->where(function ($q) use ($search) {
                $q->where('no_invoice', 'like', "%{$search}%")
                    ->orWhere('kode_registrasi', 'like', "%{$search}%")
                    ->orWhereHas('registrasi.pasien', function ($p) use ($search) {
                        $p->where('nama', 'like', "%{$search}%")
                            ->orWhere('no_rm', 'like', "%{$search}%")
                            ->orWhere('no_hp', 'like', "%{$search}%");
                    })
                    ->orWhereHas('registrasi.dokterAwal', function ($d) use ($search) {
                        $d->where('nama', 'like', "%{$search}%");
                    });
            });
        }

        $summaryQuery = clone $query;

        $rows = $query
            ->orderByDesc('tanggal_invoice')
            ->orderByDesc('id')
            ->paginate((int) $request->get('per_page', 15));

        $items = $rows->getCollection()
            ->map(fn ($invoice) => $this->formatPaymentRow($invoice))
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
            'summary' => $this->buildSummary($summaryQuery),
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

        $task = $this->getPaymentTask($registrasi);

        DB::beginTransaction();

        try {
            $invoice->update([
                'status' => PembayaranInvoice::STATUS_PROSES,
                'updated_by' => $this->username(),
                'updated_at' => now(),
            ]);

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
        $grandTotal = (float) $invoice->grand_total;

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

            $invoice->update([
                'tanggal_lunas' => now(),
                'total_bayar' => $totalBayar,
                'status' => PembayaranInvoice::STATUS_LUNAS,
                'updated_by' => $this->username(),
                'updated_at' => now(),
            ]);

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
                    : 'Pembayaran berhasil. Pelayanan selesai.',
                'next_task' => $nextTask?->task_type,
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
            $this->recalculateInvoice($invoice);

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
                'message' => 'Invoice lunas tidak bisa dibatalkan dari endpoint ini',
            ], 422);
        }

        DB::beginTransaction();

        try {
            $invoice->update([
                'status' => PembayaranInvoice::STATUS_BATAL,
                'is_delete' => 1,
                'updated_by' => $this->username(),
                'updated_at' => now(),
            ]);

            $invoice->items()->update([
                'status' => PembayaranInvoiceItem::STATUS_BATAL,
                'is_delete' => 1,
                'updated_by' => $this->username(),
                'updated_at' => now(),
            ]);

            $invoice->metode()->update([
                'status' => PembayaranInvoiceMetode::STATUS_BATAL,
                'is_delete' => 1,
                'updated_by' => $this->username(),
                'updated_at' => now(),
            ]);

            $invoice->promos()->update([
                'is_delete' => 1,
                'updated_by' => $this->username(),
                'updated_at' => now(),
            ]);

            $invoice->depositClaims()->update([
                'status' => PembayaranDepositTreatmentClaim::STATUS_BATAL,
                'is_delete' => 1,
                'updated_by' => $this->username(),
                'updated_at' => now(),
            ]);

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

    private function syncPendingInvoices(Request $request)
    {
        $query = RegistrasiKunjungan::query()
            ->with([
                'pasien',
                'tasks',
                'treatmentDetails',
                'penjualanDetails',
            ])
            ->active()
            ->where('current_task', RegistrasiTask::TYPE_PEMBAYARAN)
            ->whereHas('tasks', function ($q) {
                $q->where('task_type', RegistrasiTask::TYPE_PEMBAYARAN)
                    ->whereIn('status', [
                        RegistrasiTask::STATUS_MENUNGGU,
                        RegistrasiTask::STATUS_PROSES,
                    ]);
            })
            ->whereNotIn('id', PembayaranInvoice::query()
                ->active()
                ->select('registrasi_id')
            );

        if ($request->filled('toko_id')) {
            $query->where('toko_id', $request->toko_id);
        }

        if ($request->filled('tanggal')) {
            $query->whereDate('tanggal_kunjungan', $request->tanggal);
        }

        $query->limit(50)->get()->each(function ($registrasi) {
            $this->generateInvoiceFromRegistrasi($registrasi, false);
        });
    }

    private function generateInvoiceFromRegistrasi(
        RegistrasiKunjungan $registrasi,
        bool $forceRebuild = false
    ) {
        $registrasi->loadMissing([
            'pasien',
            'tasks',
            'treatmentDetails',
            'penjualanDetails',
        ]);

        $paymentTask = $this->getPaymentTask($registrasi);

        if (!$paymentTask) {
            throw new \Exception('Task pembayaran tidak ditemukan pada registrasi ini');
        }

        $existing = PembayaranInvoice::query()
            ->active()
            ->where('registrasi_id', $registrasi->id)
            ->first();

        if ($existing && !$forceRebuild) {
            return $existing;
        }

        if ($existing && (int) $existing->status === PembayaranInvoice::STATUS_LUNAS) {
            throw new \Exception('Invoice sudah lunas dan tidak bisa dibuat ulang');
        }

        if ($existing) {
            $existing->items()->update([
                'status' => PembayaranInvoiceItem::STATUS_BATAL,
                'is_delete' => 1,
                'updated_by' => $this->username(),
                'updated_at' => now(),
            ]);

            $existing->promos()->update([
                'is_delete' => 1,
                'updated_by' => $this->username(),
                'updated_at' => now(),
            ]);

            $existing->depositClaims()->update([
                'status' => PembayaranDepositTreatmentClaim::STATUS_BATAL,
                'is_delete' => 1,
                'updated_by' => $this->username(),
                'updated_at' => now(),
            ]);

            $invoice = $existing;
        } else {
            $invoice = PembayaranInvoice::create([
                'registrasi_id' => $registrasi->id,
                'task_id' => $paymentTask->id,
                'no_invoice' => $this->generateNoInvoice($registrasi),
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
                'catatan' => $registrasi->catatan_registrasi ?? null,

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

                'status' => PembayaranInvoice::STATUS_MENUNGGU,
                'is_delete' => 0,
                'created_by' => $this->username(),
                'created_at' => now(),
            ]);
        }

        $this->insertInvoiceItems($invoice, $registrasi);
        $this->recalculateInvoice($invoice);

        return $invoice->fresh([
            'registrasi.pasien',
            'registrasi.dokterAwal',
            'registrasi.perawatAwal',
            'items',
            'metode',
            'promos',
            'depositClaims',
        ]);
    }

    private function insertInvoiceItems(
        PembayaranInvoice $invoice,
        RegistrasiKunjungan $registrasi
    ) {

        $this->insertConsultationInvoiceItem($invoice, $registrasi);

        foreach ($registrasi->treatmentDetails as $item) {
            if (isset($item->is_delete) && (int) $item->is_delete === 1) {
                continue;
            }

            $qty = (float) ($item->jumlah ?? 1);
            $harga = (float) ($item->harga ?? 0);
            $subtotal = (float) ($item->total ?? ($qty * $harga));

            $invoiceItem = PembayaranInvoiceItem::create([
                'pembayaran_id' => $invoice->id,
                'registrasi_id' => $registrasi->id,

                'item_type' => PembayaranInvoiceItem::ITEM_TREATMENT,
                'source_type' => PembayaranInvoiceItem::SOURCE_REGISTRASI_TREATMENT,
                'source_detail_id' => $item->id,

                'deposit_treatment_id' => $item->deposit_treatment_id ?? null,
                'deposit_claim_id' => $item->deposit_claim_id ?? null,
                'expired_at' => null,

                'treatment_id' => $item->treatment_id ?? null,
                'treatment_toko_id' => $item->treatment_toko_id ?? null,

                'produk_id' => null,
                'produk_toko_id' => null,

                'nama_item' => $item->nama_treatment ?? '-',
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

                'frekuensi' => null,
                'waktu_pakai' => null,
                'instruksi_pemakaian' => null,

                'status' => PembayaranInvoiceItem::STATUS_AKTIF,
                'is_delete' => 0,
                'created_by' => $this->username(),
                'created_at' => now(),
            ]);

            if ($this->isTrue($item->is_deposit_claim ?? false) && !empty($item->deposit_treatment_id)) {
                PembayaranDepositTreatmentClaim::create([
                    'deposit_treatment_id' => $item->deposit_treatment_id,
                    'registrasi_id' => $registrasi->id,
                    'pembayaran_id' => $invoice->id,
                    'pembayaran_item_id' => $invoiceItem->id,
                    'toko_claim_id' => $registrasi->toko_id,
                    'treatment_detail_id' => $item->id,

                    'qty_claim' => $qty,
                    'nilai_realisasi' => $subtotal,

                    'claim_dokter_id' => $registrasi->dokter_awal_id,
                    'claim_perawat_id' => $registrasi->perawat_awal_id,
                    'claimed_at' => now(),

                    'status' => PembayaranDepositTreatmentClaim::STATUS_AKTIF,
                    'is_delete' => 0,
                    'created_by' => $this->username(),
                    'created_at' => now(),
                ]);
            }
        }

        foreach ($registrasi->penjualanDetails as $item) {
            if (isset($item->is_delete) && (int) $item->is_delete === 1) {
                continue;
            }

            $qty = (float) ($item->jumlah ?? 1);
            $harga = (float) ($item->harga ?? 0);
            $subtotal = (float) ($item->subtotal ?? ($qty * $harga));

            PembayaranInvoiceItem::create([
                'pembayaran_id' => $invoice->id,
                'registrasi_id' => $registrasi->id,

                'item_type' => PembayaranInvoiceItem::ITEM_PRODUK,
                'source_type' => PembayaranInvoiceItem::SOURCE_REGISTRASI_PENJUALAN,
                'source_detail_id' => $item->id,

                'deposit_treatment_id' => null,
                'deposit_claim_id' => null,
                'expired_at' => null,

                'treatment_id' => null,
                'treatment_toko_id' => null,

                'produk_id' => $item->produk_id ?? null,
                'produk_toko_id' => $item->produk_toko_id ?? null,

                'nama_item' => $item->nama_produk ?? '-',
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

    private function insertConsultationInvoiceItem(PembayaranInvoice $invoice, RegistrasiKunjungan $registrasi)
    {
        $hasConsultation =
            in_array((int) $registrasi->channel_konsultasi, [
                RegistrasiKunjungan::CHANNEL_OFFLINE,
                RegistrasiKunjungan::CHANNEL_ONLINE,
            ], true)
            || (int) ($registrasi->is_konsultasi_tambahan_dokter ?? 0) === 1
            || (float) ($registrasi->total_konsultasi ?? 0) > 0;

        if (!$hasConsultation) {
            return;
        }

        $subtotal = (float) ($registrasi->total_konsultasi ?? 0);

        PembayaranInvoiceItem::create([
            'pembayaran_id' => $invoice->id,
            'registrasi_id' => $registrasi->id,
            'item_type' => PembayaranInvoiceItem::ITEM_KONSULTASI,
            'source_type' => PembayaranInvoiceItem::SOURCE_REGISTRASI_KONSULTASI,
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

    private function recalculateInvoice(PembayaranInvoice $invoice)
    {
        $invoice->loadMissing(['items', 'promos', 'depositClaims']);

        $items = $invoice->items
            ->where('is_delete', 0)
            ->where('status', PembayaranInvoiceItem::STATUS_AKTIF);

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

        $totalPromo = (float) $invoice->promos
            ->where('is_delete', 0)
            ->sum('diskon_amount');

        $totalDepositClaim = (float) $invoice->depositClaims
            ->where('is_delete', 0)
            ->where('status', PembayaranDepositTreatmentClaim::STATUS_AKTIF)
            ->sum('nilai_realisasi');

        $diskonSubtotalAmount = (float) ($invoice->diskon_subtotal_amount ?? 0);
        $diskonMemberAmount = (float) ($invoice->diskon_member_amount ?? 0);
        $pointRedeemValue = (float) ($invoice->point_redeem_value ?? 0);

        $grandTotal = $subtotal
            - $totalDiskonItem
            - $totalDiskonReferral
            - $totalPromo
            - $totalDepositClaim
            - $diskonSubtotalAmount
            - $diskonMemberAmount
            - $pointRedeemValue;

        if ($grandTotal < 0) {
            $grandTotal = 0;
        }

        $invoice->update([
            'subtotal_produk' => $subtotalProduk,
            'subtotal_treatment' => $subtotalTreatment,
            'subtotal_konsultasi' => $subtotalKonsultasi,
            'subtotal' => $subtotal,

            'total_diskon_item' => $totalDiskonItem,
            'total_diskon_referral' => $totalDiskonReferral,
            'total_promo' => $totalPromo,

            'grand_total' => $grandTotal,

            'updated_by' => $this->username(),
            'updated_at' => now(),
        ]);
    }

    private function replaceInvoiceMetode(PembayaranInvoice $invoice, array $metode)
    {
        $invoice->metode()->update([
            'status' => PembayaranInvoiceMetode::STATUS_BATAL,
            'is_delete' => 1,
            'updated_by' => $this->username(),
            'updated_at' => now(),
        ]);

        foreach ($metode as $index => $row) {
            $dialokasikan = (float) ($row['nominal_dialokasikan'] ?? 0);
            $diterima = (float) ($row['nominal_diterima'] ?? $dialokasikan);

            PembayaranInvoiceMetode::create([
                'pembayaran_id' => $invoice->id,
                'metode_bayar_id' => $row['metode_bayar_id'] ?? null,
                'metode_bayar_nama' => $row['metode_bayar_nama'] ?? 'Cash',
                'metode_bayar_tipe' => $row['metode_bayar_tipe']
                    ?? PembayaranInvoiceMetode::TIPE_CASH_BANK_EDC_QRIS,

                'nominal_dialokasikan' => $dialokasikan,
                'nominal_diterima' => $diterima,
                'nominal_kembalian' => max(0, $diterima - $dialokasikan),

                'no_referensi' => $row['no_referensi'] ?? null,
                'catatan' => $row['catatan'] ?? null,
                'sort_order' => $index + 1,

                'status' => PembayaranInvoiceMetode::STATUS_AKTIF,
                'is_delete' => 0,
                'created_by' => $this->username(),
                'created_at' => now(),
            ]);
        }
    }

    private function normalizeMetodePayload(Request $request, PembayaranInvoice $invoice)
    {
        if (is_array($request->metode) && count($request->metode)) {
            return collect($request->metode)
                ->map(function ($row) {
                    $dialokasikan = (float) ($row['nominal_dialokasikan'] ?? 0);

                    return [
                        'metode_bayar_id' => $row['metode_bayar_id'] ?? null,
                        'metode_bayar_nama' => $row['metode_bayar_nama'] ?? 'Cash',
                        'metode_bayar_tipe' => $row['metode_bayar_tipe']
                            ?? PembayaranInvoiceMetode::TIPE_CASH_BANK_EDC_QRIS,
                        'nominal_dialokasikan' => $dialokasikan,
                        'nominal_diterima' => (float) ($row['nominal_diterima'] ?? $dialokasikan),
                        'no_referensi' => $row['no_referensi'] ?? null,
                        'catatan' => $row['catatan'] ?? null,
                    ];
                })
                ->filter(fn ($row) => $row['nominal_dialokasikan'] > 0)
                ->values()
                ->all();
        }

        $jumlahBayar = $request->filled('jumlah_bayar')
            ? (float) $request->jumlah_bayar
            : (float) $invoice->grand_total;

        return [
            [
                'metode_bayar_id' => null,
                'metode_bayar_nama' => $request->input('metode_pembayaran', 'Cash'),
                'metode_bayar_tipe' => PembayaranInvoiceMetode::TIPE_CASH_BANK_EDC_QRIS,
                'nominal_dialokasikan' => $jumlahBayar,
                'nominal_diterima' => $jumlahBayar,
                'no_referensi' => null,
                'catatan' => $request->input('catatan_pembayaran'),
            ],
        ];
    }

    private function resolveInvoice($id)
    {
        return PembayaranInvoice::query()
            ->with([
                'registrasi.pasien',
                'registrasi.dokterAwal',
                'registrasi.perawatAwal',
                'registrasi.tasks' => function ($q) {
                    $q->orderBy('task_order');
                },
                'items',
                'metode',
                'promos',
                'depositClaims',
            ])
            ->active()
            ->where(function ($q) use ($id) {
                $q->where('id', $id)
                    ->orWhere('registrasi_id', $id);
            })
            ->first();
    }

    private function buildSummary($query)
    {
        $rows = $query->get();

        return [
            'total' => $rows->count(),
            'menunggu' => $rows->where('status', PembayaranInvoice::STATUS_MENUNGGU)->count(),
            'diproses' => $rows->where('status', PembayaranInvoice::STATUS_PROSES)->count(),
            'lunas' => $rows->where('status', PembayaranInvoice::STATUS_LUNAS)->count(),
        ];
    }

    private function formatPaymentRow(PembayaranInvoice $invoice)
    {
        $invoice->loadMissing([
            'registrasi.pasien',
            'registrasi.dokterAwal',
            'registrasi.perawatAwal',
            'registrasi.tasks',
            'items',
            'metode',
            'promos',
            'depositClaims',
        ]);

        $registrasi = $invoice->registrasi;
        $paymentTask = $registrasi ? $this->getPaymentTask($registrasi) : null;

        return [
            'id' => $invoice->id,
            'pembayaran_id' => $invoice->id,
            'invoice_id' => $invoice->id,
            'registrasi_id' => $invoice->registrasi_id,

            'nomor_invoice' => $invoice->no_invoice,
            'nomor_kunjungan' => $invoice->kode_registrasi,
            'kode_registrasi' => $invoice->kode_registrasi,

            'toko_id' => $invoice->toko_id,
            'pasien_id' => $invoice->pasien_id,

            'nama_pasien' => $registrasi?->pasien?->nama,
            'no_rm' => $registrasi?->pasien?->no_rm,
            'no_hp' => $registrasi?->pasien?->no_hp,

            'nama_dokter' => $registrasi?->dokterAwal?->nama,
            'nama_perawat' => $registrasi?->perawatAwal?->nama,

            'tanggal_kunjungan' => $registrasi?->tanggal_kunjungan,
            'waktu_kunjungan' => $this->formatTime($registrasi?->registered_at),

            'tanggal_invoice' => $invoice->tanggal_invoice,
            'tanggal_lunas' => $invoice->tanggal_lunas,

            'channel_konsultasi' => $registrasi?->channel_konsultasi,
            'ada_konsultasi' => $registrasi ? $this->hasConsultation($registrasi) : false,
            'ada_treatment' => (int) ($registrasi?->is_treatment ?? 0) === 1,
            'ada_penjualan' => (int) ($registrasi?->is_penjualan ?? 0) === 1,

            'channel_label' => $registrasi ? $this->formatChannel($registrasi->channel_konsultasi) : '-',
            'layanan_label' => $registrasi ? $this->formatLayanan($registrasi) : '-',

            'subtotal_produk' => (float) $invoice->subtotal_produk,
            'subtotal_treatment' => (float) $invoice->subtotal_treatment,
            'subtotal_konsultasi' => (float) $invoice->subtotal_konsultasi,
            'subtotal' => (float) $invoice->subtotal,

            'total_diskon_item' => (float) $invoice->total_diskon_item,
            'total_diskon_referral' => (float) $invoice->total_diskon_referral,
            'total_promo' => (float) $invoice->total_promo,
            'total_deposit_claim' => (float) $invoice->depositClaims
                ->where('is_delete', 0)
                ->where('status', PembayaranDepositTreatmentClaim::STATUS_AKTIF)
                ->sum('nilai_realisasi'),

            'grand_total' => (float) $invoice->grand_total,
            'total_tagihan' => (float) $invoice->grand_total,
            'total_bayar' => (float) $invoice->total_bayar,

            'metode_pembayaran' => $invoice->metode
                ->where('is_delete', 0)
                ->pluck('metode_bayar_nama')
                ->implode(', '),

            'status_pembayaran_key' => $this->mapInvoiceStatusToKey($invoice->status),
            'status' => $this->mapInvoiceStatusToLabel($invoice->status),
            'status_invoice' => (int) $invoice->status,

            'payment_task_id' => $paymentTask?->id,
            'can_process_pembayaran' => in_array((int) $invoice->status, [
                PembayaranInvoice::STATUS_MENUNGGU,
                PembayaranInvoice::STATUS_PROSES,
            ], true),

            'items_count' => $invoice->items->where('is_delete', 0)->count(),
        ];
    }

    private function processStockKeluarPembayaran(
        PembayaranInvoice $invoice,
        RegistrasiKunjungan $registrasi
    ) {
        $invoice->loadMissing(['items']);

        $productItems = $invoice->items
            ->where('is_delete', 0)
            ->where('status', PembayaranInvoiceItem::STATUS_AKTIF)
            ->where('item_type', PembayaranInvoiceItem::ITEM_PRODUK)
            ->values();

        if ($productItems->isEmpty()) {
            return;
        }

        $hasActiveReserve = StockReservasiProduk::query()
            ->where('source_type', 'REGISTRASI_LAYANAN')
            ->where('source_id', $registrasi->id)
            ->where('status', 'ACTIVE')
            ->exists();

        if ($hasActiveReserve) {
            $this->stockTransactionService->consumeReservasiUntukPenjualan(
                'REGISTRASI_LAYANAN',
                $registrasi->id,
                [
                    'kode_mutasi' => $invoice->no_invoice,
                    'tanggal' => now(),

                    'ref_type' => 'PEMBAYARAN',
                    'ref_table' => 'pembayaran_invoice',
                    'ref_id' => $invoice->id,

                    'keterangan' => 'Stok keluar dari pembayaran registrasi layanan',
                    'created_by' => $this->username(),
                ]
            );

            return;
        }

        $items = [];

        foreach ($productItems as $item) {
            if (empty($item->produk_toko_id) || empty($item->produk_id)) {
                throw new \Exception('Produk invoice belum memiliki mapping produk_toko_id / produk_id pada item: ' . ($item->nama_item ?? '-'));
            }

            $items[] = [
                'produk_toko_id' => $item->produk_toko_id,
                'produk_id' => $item->produk_id,
                'tempat_produk_id' => $this->resolveTempatProdukIdFromInvoiceItem($item),
                'qty' => (float) ($item->qty ?? 0),
                'harga_jual' => (float) ($item->harga ?? 0),
                'ref_detail_id' => $item->id,
            ];
        }

        $this->stockTransactionService->keluarPenjualanTanpaReservasi($items, [
            'toko_id' => $invoice->toko_id,
            'kode_mutasi' => $invoice->no_invoice,
            'tanggal' => now(),

            'ref_type' => 'PEMBAYARAN',
            'ref_table' => 'pembayaran_invoice',
            'ref_id' => $invoice->id,

            'keterangan' => 'Stok keluar dari pembayaran penjualan tanpa reservasi',
            'created_by' => $this->username(),
        ]);
    }

    private function resolveTempatProdukIdFromInvoiceItem(PembayaranInvoiceItem $item)
    {
        if (isset($item->tempat_produk_id) && !empty($item->tempat_produk_id)) {
            return (int) $item->tempat_produk_id;
        }

        $produkId = $item->produk_id;

        if (!empty($item->produk_toko_id) && Schema::hasTable('master_produk_toko')) {
            $produkToko = DB::table('master_produk_toko')
                ->where('id', $item->produk_toko_id)
                ->first();

            if ($produkToko) {
                if (Schema::hasColumn('master_produk_toko', 'tempat_produk_id') && !empty($produkToko->tempat_produk_id)) {
                    return (int) $produkToko->tempat_produk_id;
                }

                if (Schema::hasColumn('master_produk_toko', 'tempat_produk_id') && !empty($produkToko->tempat_produk_id)) {
                    return (int) $produkToko->tempat_produk_id;
                }

                $produkId = $produkToko->produk_id
                    ?? $produkToko->master_produk_id
                    ?? $produkToko->obat_id
                    ?? $produkId;
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

        $row = DB::table('master_produk')
            ->where('id', $produkId)
            ->first();

        if (!$row) {
            return null;
        }

        if (Schema::hasColumn('master_produk', 'tempat_produk_id') && !empty($row->tempat_produk_id)) {
            return (int) $row->tempat_produk_id;
        }

        if (Schema::hasColumn('master_produk', 'tempat_produk_id') && !empty($row->tempat_produk_id)) {
            return (int) $row->tempat_produk_id;
        }

        return null;
    }

    private function getPaymentTask(RegistrasiKunjungan $registrasi)
    {
        if ($registrasi->relationLoaded('tasks')) {
            return $registrasi->tasks
                ->where('task_type', RegistrasiTask::TYPE_PEMBAYARAN)
                ->sortBy('task_order')
                ->first();
        }

        return $registrasi->tasks()
            ->where('task_type', RegistrasiTask::TYPE_PEMBAYARAN)
            ->orderBy('task_order')
            ->first();
    }

    private function generateNoInvoice(RegistrasiKunjungan $registrasi)
    {
        $date = Carbon::parse($registrasi->tanggal_kunjungan ?? now())->format('Ymd');

        $count = PembayaranInvoice::query()
            ->where('toko_id', $registrasi->toko_id)
            ->whereDate('tanggal_invoice', Carbon::parse($registrasi->tanggal_kunjungan ?? now())->toDateString())
            ->count() + 1;

        return 'INV-' .
            $date .
            '-' .
            str_pad($registrasi->toko_id, 2, '0', STR_PAD_LEFT) .
            '-' .
            str_pad($count, 4, '0', STR_PAD_LEFT);
    }

    private function applyChannelFilter($query, $channel)
    {
        $channel = strtolower(trim((string) $channel));

        if ($channel === 'offline') {
            $query->whereHas('registrasi', function ($q) {
                $q->where('channel_konsultasi', RegistrasiKunjungan::CHANNEL_OFFLINE);
            });
            return;
        }

        if ($channel === 'online') {
            $query->whereHas('registrasi', function ($q) {
                $q->where('channel_konsultasi', RegistrasiKunjungan::CHANNEL_ONLINE);
            });
            return;
        }

        if (in_array($channel, ['tanpa_konsultasi', 'tanpa konsultasi'], true)) {
            $query->whereHas('registrasi', function ($q) {
                $q->where('channel_konsultasi', RegistrasiKunjungan::CHANNEL_TIDAK_KONSULTASI);
            });
        }
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

    private function mapRequestStatusToInvoiceStatus($status)
    {
        $status = strtolower(trim((string) $status));

        return match ($status) {
            'menunggu', 'menunggu pembayaran' => PembayaranInvoice::STATUS_MENUNGGU,
            'proses', 'diproses' => PembayaranInvoice::STATUS_PROSES,
            'lunas', 'selesai' => PembayaranInvoice::STATUS_LUNAS,
            'batal' => PembayaranInvoice::STATUS_BATAL,
            default => null,
        };
    }

    private function mapInvoiceStatusToKey($status)
    {
        return match ((int) $status) {
            PembayaranInvoice::STATUS_PROSES => 'proses',
            PembayaranInvoice::STATUS_LUNAS => 'lunas',
            PembayaranInvoice::STATUS_BATAL => 'batal',
            default => 'menunggu',
        };
    }

    private function mapInvoiceStatusToLabel($status)
    {
        return match ((int) $status) {
            PembayaranInvoice::STATUS_PROSES => 'Diproses',
            PembayaranInvoice::STATUS_LUNAS => 'Lunas',
            PembayaranInvoice::STATUS_BATAL => 'Batal',
            default => 'Menunggu Pembayaran',
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

    private function isTrue($value)
    {
        if (is_bool($value)) {
            return $value;
        }

        if (is_numeric($value)) {
            return (int) $value === 1;
        }

        return in_array(strtolower((string) $value), ['1', 'true', 'yes', 'ya'], true);
    }

    private function username()
    {
        return auth()->user()->username
            ?? auth()->user()->name
            ?? 'system';
    }
}