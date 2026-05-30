<?php

namespace App\Http\Controllers\Api\Kasir;

use App\Http\Controllers\Controller;
use App\Models\Pembayaran\PembayaranInvoice;
use App\Models\Pembayaran\PembayaranInvoiceItem;
use App\Models\Pembayaran\PembayaranInvoiceMetode;
use App\Models\Registrasi\RegistrasiKunjungan;
use App\Models\Registrasi\RegistrasiTask;
use App\Models\Pembayaran\PembayaranDepositTreatmentClaim;
use App\Models\Stock\StockProdukToko;
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

    private function syncPendingInvoicesLite(Request $request): void
    {
        $query = RegistrasiKunjungan::query()
            ->select([
                'id',
                'kode_registrasi',
                'toko_id',
                'pasien_id',
                'dokter_awal_id',
                'perawat_awal_id',
                'tanggal_kunjungan',
                'registered_at',
                'channel_konsultasi',
                'is_treatment',
                'is_penjualan',
                'total_treatment',
                'total_penjualan',
                'total_konsultasi',
                'grand_total',
                'catatan_registrasi',
                'current_task',
                'status',
                'is_delete',
            ])
            ->active()
            ->where('current_task', RegistrasiTask::TYPE_PEMBAYARAN)
            ->whereExists(function ($q) {
                $q->select(DB::raw(1))
                    ->from('registrasi_task')
                    ->whereColumn('registrasi_task.registrasi_id', 'registrasi_kunjungan.id')
                    ->where('registrasi_task.task_type', RegistrasiTask::TYPE_PEMBAYARAN)
                    ->whereIn('registrasi_task.status', [
                        RegistrasiTask::STATUS_MENUNGGU,
                        RegistrasiTask::STATUS_PROSES,
                    ]);
            })
            ->whereNotExists(function ($q) {
                $q->select(DB::raw(1))
                    ->from('pembayaran_invoice')
                    ->whereColumn('pembayaran_invoice.registrasi_id', 'registrasi_kunjungan.id')
                    ->where('pembayaran_invoice.is_delete', 0);
            });

        if ($request->filled('toko_id')) {
            $query->where('toko_id', (int) $request->toko_id);
        }

        if ($request->filled('tanggal')) {
            $start = \Carbon\Carbon::parse($request->tanggal)->startOfDay();
            $end = \Carbon\Carbon::parse($request->tanggal)->addDay()->startOfDay();

            $query->where('tanggal_kunjungan', '>=', $start->toDateString())
                ->where('tanggal_kunjungan', '<', $end->toDateString());
        }

        $query
            ->orderBy('id')
            ->limit(10)
            ->get()
            ->each(function ($registrasi) {
                $registrasi->loadMissing([
                    'pasien',
                    'tasks',
                    'treatmentDetails',
                    'penjualanDetails',
                ]);

                $this->generateInvoiceFromRegistrasi($registrasi, false);
            });
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
        $totalBayar = (float) collect($metode)->sum('nominal_dialokasikan');
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

            $invoice->update($this->onlyExistingColumns('pembayaran_invoice', [
                'tanggal_lunas' => now(),
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
                'message' => $nextTask ? 'Pembayaran berhasil. Registrasi dilanjutkan ke task berikutnya.' : 'Pembayaran berhasil. Registrasi selesai.',
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
            $this->releaseInvoiceReservations($invoice);

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

    private function syncPendingInvoices(Request $request): void
    {
        $query = RegistrasiKunjungan::query()
            ->with(['tasks', 'treatmentDetails', 'penjualanDetails'])
            ->active()
            ->where('status', RegistrasiKunjungan::STATUS_AKTIF)
            ->whereHas('tasks', function ($q) {
                $q->where('task_type', RegistrasiTask::TYPE_PEMBAYARAN);
            })
            ->whereDoesntHave('pembayaranInvoices', function ($q) {
                $q->where('is_delete', 0)
                    ->whereIn('status', [
                        PembayaranInvoice::STATUS_MENUNGGU,
                        PembayaranInvoice::STATUS_PROSES,
                        PembayaranInvoice::STATUS_LUNAS,
                    ]);
            });

        if ($request->filled('toko_id')) {
            $query->where('toko_id', $request->toko_id);
        }

        $query->limit(50)->get()->each(function ($registrasi) {
            $this->generateInvoiceFromRegistrasi($registrasi, false);
        });
    }

    private function generateInvoiceFromRegistrasi(RegistrasiKunjungan $registrasi, bool $forceSync = true)
    {
        $paymentTask = $this->getPaymentTask($registrasi) ?: $this->ensurePaymentTask($registrasi);

        $invoice = PembayaranInvoice::query()
            ->active()
            ->where('registrasi_id', $registrasi->id)
            ->first();

        if ($invoice && (int) $invoice->status === PembayaranInvoice::STATUS_LUNAS) {
            if ($forceSync) {
                throw new \Exception('Invoice sudah lunas dan tidak bisa disinkronkan ulang');
            }

            return $invoice;
        }

        if (!$invoice) {
            $invoice = PembayaranInvoice::create($this->onlyExistingColumns('pembayaran_invoice', [
                'registrasi_id' => $registrasi->id,
                'task_id' => $paymentTask?->id,
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
                'jenis_transaksi' => 0,
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
            ]));
        } else {
            $invoice->items()->update([
                'status' => PembayaranInvoiceItem::STATUS_BATAL,
                'is_delete' => 1,
                'updated_by' => $this->username(),
                'updated_at' => now(),
            ]);

            $invoice->update($this->onlyExistingColumns('pembayaran_invoice', [
                'task_id' => $paymentTask?->id,
                'kode_registrasi' => $registrasi->kode_registrasi,
                'toko_id' => $registrasi->toko_id,
                'pasien_id' => $registrasi->pasien_id,
                'dokter_id' => $registrasi->dokter_awal_id,
                'catatan' => $registrasi->catatan_registrasi,
                'status' => PembayaranInvoice::STATUS_MENUNGGU,
                'updated_by' => $this->username(),
                'updated_at' => now(),
            ]));
        }

        $registrasi = $registrasi->fresh(['treatmentDetails', 'penjualanDetails', 'tasks']);

        $this->insertInvoiceItems($invoice, $registrasi);
        $this->recalculateInvoice($invoice);

        return $invoice->fresh(['items', 'metode', 'promos', 'depositClaims']);
    }

    private function insertInvoiceItems(PembayaranInvoice $invoice, RegistrasiKunjungan $registrasi): void
    {
        $this->insertConsultationInvoiceItem($invoice, $registrasi);
        $this->insertTreatmentInvoiceItems($invoice, $registrasi);
        $this->insertPenjualanInvoiceItems($invoice, $registrasi);
    }

    private function insertConsultationInvoiceItem(PembayaranInvoice $invoice, RegistrasiKunjungan $registrasi): void
    {
        $hasConsultation = in_array((int) $registrasi->channel_konsultasi, [
                RegistrasiKunjungan::CHANNEL_OFFLINE,
                RegistrasiKunjungan::CHANNEL_ONLINE,
            ], true)
            || (int) ($registrasi->is_konsultasi_tambahan_dokter ?? 0) === 1
            || (float) ($registrasi->total_konsultasi ?? 0) > 0;

        if (!$hasConsultation) {
            return;
        }

        $subtotal = (float) ($registrasi->total_konsultasi ?? 0);

        PembayaranInvoiceItem::create($this->onlyExistingColumns('pembayaran_invoice_item', [
            'pembayaran_id' => $invoice->id,
            'registrasi_id' => $registrasi->id,
            'item_type' => PembayaranInvoiceItem::ITEM_KONSULTASI,
            'source_type' => 4,
            'source_detail_id' => $registrasi->id,
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
            'is_saran_dokter' => (int) ($registrasi->is_konsultasi_tambahan_dokter ?? 0) === 1 ? 1 : 0,
            'send_when_zero' => $subtotal <= 0 ? 1 : 0,
            'status' => PembayaranInvoiceItem::STATUS_AKTIF,
            'is_delete' => 0,
            'created_by' => $this->username(),
            'created_at' => now(),
        ]));
    }

    private function insertTreatmentInvoiceItems(PembayaranInvoice $invoice, RegistrasiKunjungan $registrasi): void
    {
        $items = $registrasi->treatmentDetails
            ? $registrasi->treatmentDetails->where('is_delete', 0)->where('status', '!=', 9)
            : collect();

        foreach ($items as $item) {
            $qty = (float) ($item->jumlah ?? 1);
            $harga = (float) ($item->harga ?? 0);
            $subtotal = (float) ($item->total ?? ($qty * $harga));

            PembayaranInvoiceItem::create($this->onlyExistingColumns('pembayaran_invoice_item', [
                'pembayaran_id' => $invoice->id,
                'registrasi_id' => $registrasi->id,
                'item_type' => PembayaranInvoiceItem::ITEM_TREATMENT,
                'source_type' => PembayaranInvoiceItem::SOURCE_REGISTRASI_TREATMENT,
                'source_detail_id' => $item->id,
                'deposit_treatment_id' => $item->deposit_treatment_id ?? null,
                'deposit_claim_id' => $item->deposit_claim_id ?? null,
                'expired_at' => null,
                'treatment_id' => $item->treatment_id,
                'treatment_toko_id' => $item->treatment_toko_id,
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
                'is_saran_dokter' => (int) ($item->is_saran_dokter ?? 0),
                'status' => PembayaranInvoiceItem::STATUS_AKTIF,
                'is_delete' => 0,
                'created_by' => $this->username(),
                'created_at' => now(),
            ]));
        }
    }

    private function insertPenjualanInvoiceItems(PembayaranInvoice $invoice, RegistrasiKunjungan $registrasi): void
    {
        $items = $registrasi->penjualanDetails
            ? $registrasi->penjualanDetails->where('is_delete', 0)->where('status', '!=', 9)
            : collect();

        foreach ($items as $item) {
            $qty = (float) ($item->jumlah ?? 1);
            $harga = (float) ($item->harga ?? 0);
            $subtotal = (float) ($item->subtotal ?? ($qty * $harga));

            PembayaranInvoiceItem::create($this->onlyExistingColumns('pembayaran_invoice_item', [
                'pembayaran_id' => $invoice->id,
                'registrasi_id' => $registrasi->id,
                'item_type' => PembayaranInvoiceItem::ITEM_PRODUK,
                'source_type' => PembayaranInvoiceItem::SOURCE_REGISTRASI_PENJUALAN,
                'source_detail_id' => $item->id,
                'produk_id' => $item->produk_id,
                'produk_toko_id' => $item->produk_toko_id,
                'tempat_produk_id' => $item->tempat_produk_id ?? null,
                'stock_reservasi_id' => $item->stock_reservasi_id ?? null,
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
                'is_saran_dokter' => (int) ($item->is_saran_dokter ?? 0),
                'frekuensi' => $item->frekuensi_penggunaan ?? $item->frekuensi ?? null,
                'waktu_pakai' => $item->waktu_penggunaan ?? $item->waktu_pakai ?? null,
                'instruksi_pemakaian' => $item->instruksi_pemakaian ?? null,
                'status' => PembayaranInvoiceItem::STATUS_AKTIF,
                'is_delete' => 0,
                'created_by' => $this->username(),
                'created_at' => now(),
            ]));
        }
    }

    private function recalculateInvoice(PembayaranInvoice $invoice): void
    {
        $items = PembayaranInvoiceItem::query()
            ->where('pembayaran_id', $invoice->id)
            ->where('is_delete', 0)
            ->where('status', PembayaranInvoiceItem::STATUS_AKTIF)
            ->get();

        $subtotalProduk = (float) $items->where('item_type', PembayaranInvoiceItem::ITEM_PRODUK)->sum('subtotal');
        $subtotalTreatment = (float) $items->where('item_type', PembayaranInvoiceItem::ITEM_TREATMENT)->sum('subtotal');
        $subtotalKonsultasi = (float) $items->where('item_type', PembayaranInvoiceItem::ITEM_KONSULTASI)->sum('subtotal');
        $subtotal = $subtotalProduk + $subtotalTreatment + $subtotalKonsultasi;
        $totalDiskonItem = (float) $items->sum('diskon_amount');
        $totalDiskonReferral = (float) $items->sum('diskon_referral');
        $grandTotal = max(0, $subtotal - $totalDiskonItem - $totalDiskonReferral);
        $totalBayar = (float) ($invoice->total_bayar ?? 0);

        $invoice->update($this->onlyExistingColumns('pembayaran_invoice', [
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
        ]));
    }

    private function processStockKeluarPembayaran(PembayaranInvoice $invoice, RegistrasiKunjungan $registrasi): void
    {
        $items = $invoice->items()
            ->where('is_delete', 0)
            ->where('status', PembayaranInvoiceItem::STATUS_AKTIF)
            ->where('item_type', PembayaranInvoiceItem::ITEM_PRODUK)
            ->get();

        foreach ($items as $item) {
            $qty = (float) $item->qty;

            if ($qty <= 0) {
                continue;
            }

            $stock = null;
            $reservasi = null;

            if (Schema::hasColumn('pembayaran_invoice_item', 'stock_reservasi_id') && $item->stock_reservasi_id) {
                $reservasi = StockReservasiProduk::query()
                    ->where('id', $item->stock_reservasi_id)
                    ->where('status', 'ACTIVE')
                    ->lockForUpdate()
                    ->first();

                if ($reservasi) {
                    $stock = StockProdukToko::query()
                        ->where('produk_toko_id', $reservasi->produk_toko_id)
                        ->where('toko_id', $reservasi->toko_id)
                        ->where('tempat_produk_id', $reservasi->tempat_produk_id)
                        ->lockForUpdate()
                        ->first();
                }
            }

            if (!$stock) {
                $stockQuery = StockProdukToko::query()
                    ->where('toko_id', $invoice->toko_id)
                    ->where('produk_toko_id', $item->produk_toko_id)
                    ->where('is_delete', 0)
                    ->lockForUpdate();

                if (Schema::hasColumn('pembayaran_invoice_item', 'tempat_produk_id') && $item->tempat_produk_id) {
                    $stockQuery->where('tempat_produk_id', $item->tempat_produk_id);
                }

                $stock = $stockQuery->orderByRaw('(stok_akhir - stok_reserved) DESC')->first();
            }

            if (!$stock) {
                throw new \Exception('Stok produk tidak ditemukan untuk item: ' . $item->nama_item);
            }

            $available = (float) $stock->stok_akhir - (float) $stock->stok_reserved;

            if (!$reservasi && $available < $qty) {
                throw new \Exception("Stok produk {$item->nama_item} tidak mencukupi. Tersedia {$available}, diminta {$qty}");
            }

            $reservedDelta = $reservasi ? min((float) $stock->stok_reserved, $qty) : 0;

            $stock->update([
                'stok_keluar' => (float) $stock->stok_keluar + $qty,
                'stok_akhir' => (float) $stock->stok_akhir - $qty,
                'stok_reserved' => max(0, (float) $stock->stok_reserved - $reservedDelta),
                'last_mutation_at' => now(),
                'updated_by' => $this->username(),
                'updated_at' => now(),
            ]);

            if ($reservasi) {
                $reservasi->update([
                    'status' => 'CONSUMED',
                    'consumed_at' => now(),
                    'updated_by' => $this->username(),
                    'updated_at' => now(),
                ]);
            }
        }
    }

    private function releaseInvoiceReservations(PembayaranInvoice $invoice): void
    {
        if (!Schema::hasColumn('pembayaran_invoice_item', 'stock_reservasi_id')) {
            return;
        }

        $items = $invoice->items()
            ->whereNotNull('stock_reservasi_id')
            ->where('is_delete', 0)
            ->get();

        foreach ($items as $item) {
            $reservasi = StockReservasiProduk::query()
                ->where('id', $item->stock_reservasi_id)
                ->where('status', 'ACTIVE')
                ->lockForUpdate()
                ->first();

            if (!$reservasi) {
                continue;
            }

            $stock = StockProdukToko::query()
                ->where('produk_toko_id', $reservasi->produk_toko_id)
                ->where('toko_id', $reservasi->toko_id)
                ->where('tempat_produk_id', $reservasi->tempat_produk_id)
                ->lockForUpdate()
                ->first();

            if ($stock) {
                $stock->update([
                    'stok_reserved' => max(0, (float) $stock->stok_reserved - (float) $reservasi->qty_reserved),
                    'updated_by' => $this->username(),
                    'updated_at' => now(),
                ]);
            }

            $reservasi->update([
                'status' => 'RELEASED',
                'released_at' => now(),
                'updated_by' => $this->username(),
                'updated_at' => now(),
            ]);
        }
    }

    private function replaceInvoiceMetode(PembayaranInvoice $invoice, array $metode): void
    {
        $invoice->metode()->update([
            'status' => PembayaranInvoiceMetode::STATUS_BATAL,
            'is_delete' => 1,
            'updated_by' => $this->username(),
            'updated_at' => now(),
        ]);

        foreach ($metode as $index => $row) {
            PembayaranInvoiceMetode::create($this->onlyExistingColumns('pembayaran_invoice_metode', [
                'pembayaran_id' => $invoice->id,
                'metode_bayar_id' => $row['metode_bayar_id'] ?? null,
                'metode_bayar_nama' => $row['metode_bayar_nama'],
                'metode_bayar_tipe' => $row['metode_bayar_tipe'] ?? 1,
                'nominal_dialokasikan' => $row['nominal_dialokasikan'],
                'nominal_diterima' => $row['nominal_diterima'] ?? $row['nominal_dialokasikan'],
                'nominal_kembalian' => max(0, ($row['nominal_diterima'] ?? $row['nominal_dialokasikan']) - $row['nominal_dialokasikan']),
                'no_referensi' => $row['no_referensi'] ?? null,
                'catatan' => $row['catatan'] ?? null,
                'sort_order' => $index + 1,
                'status' => PembayaranInvoiceMetode::STATUS_AKTIF,
                'is_delete' => 0,
                'created_by' => $this->username(),
                'created_at' => now(),
            ]));
        }
    }

    private function normalizeMetodePayload(Request $request, PembayaranInvoice $invoice): array
    {
        $metode = $request->input('metode', []);

        if (is_array($metode) && count($metode) > 0) {
            return collect($metode)
                ->filter(fn ($row) => (float) ($row['nominal_dialokasikan'] ?? 0) > 0)
                ->map(function ($row) {
                    return [
                        'metode_bayar_id' => $row['metode_bayar_id'] ?? null,
                        'metode_bayar_nama' => $row['metode_bayar_nama'] ?? 'Pembayaran',
                        'metode_bayar_tipe' => $row['metode_bayar_tipe'] ?? 1,
                        'nominal_dialokasikan' => (float) ($row['nominal_dialokasikan'] ?? 0),
                        'nominal_diterima' => (float) ($row['nominal_diterima'] ?? $row['nominal_dialokasikan'] ?? 0),
                        'no_referensi' => $row['no_referensi'] ?? null,
                        'catatan' => $row['catatan'] ?? null,
                    ];
                })
                ->values()
                ->all();
        }

        return [[
            'metode_bayar_id' => null,
            'metode_bayar_nama' => $request->input('metode_pembayaran', 'Tunai'),
            'metode_bayar_tipe' => 1,
            'nominal_dialokasikan' => (float) $request->input('jumlah_bayar', $invoice->grand_total),
            'nominal_diterima' => (float) $request->input('jumlah_bayar', $invoice->grand_total),
            'no_referensi' => null,
            'catatan' => $request->input('catatan_pembayaran'),
        ]];
    }

    private function resolveInvoice($id)
    {
        return PembayaranInvoice::query()
            ->with([
                'registrasi.toko',
                'registrasi.pasien',
                'registrasi.dokterAwal',
                'registrasi.perawatAwal',
                'registrasi.tasks',
                'items',
                'metode',
                'promos',
                'depositClaims',
            ])
            ->active()
            ->where(function ($q) use ($id) {
                $q->where('id', $id)
                    ->orWhere('no_invoice', $id);
            })
            ->first();
    }

    private function ensurePaymentTask(RegistrasiKunjungan $registrasi)
    {
        $paymentTask = $this->getPaymentTask($registrasi);

        if ($paymentTask) {
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

    private function formatPaymentListRow(PembayaranInvoice $invoice): array
    {
        $registrasi = $invoice->registrasi;

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
            'total_deposit_claim' => (float) ($invoice->total_deposit_claim ?? 0),
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

            'payment_task_id' => null,
            'can_process_pembayaran' => in_array((int) $invoice->status, [
                PembayaranInvoice::STATUS_MENUNGGU,
                PembayaranInvoice::STATUS_PROSES,
            ], true),

            'items_count' => (int) ($invoice->items_count ?? 0),
        ];
    }

    private function applyPaymentListFilters($query, Request $request): void
    {
        if ($request->filled('toko_id')) {
            $query->where('toko_id', (int) $request->toko_id);
        }

        if ($request->filled('tanggal')) {
            $start = \Carbon\Carbon::parse($request->tanggal)->startOfDay();
            $end = \Carbon\Carbon::parse($request->tanggal)->addDay()->startOfDay();

            $query->where('tanggal_invoice', '>=', $start)
                ->where('tanggal_invoice', '<', $end);
        }

        if ($request->filled('tanggal_mulai')) {
            $start = \Carbon\Carbon::parse($request->tanggal_mulai)->startOfDay();

            $query->where('tanggal_invoice', '>=', $start);
        }

        if ($request->filled('tanggal_selesai')) {
            $end = \Carbon\Carbon::parse($request->tanggal_selesai)->addDay()->startOfDay();

            $query->where('tanggal_invoice', '<', $end);
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

            if ($search !== '') {
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
        }
    }

    private function buildSummaryFast($query): array
    {
        $query->setEagerLoads([]);

        $base = clone $query;

        $base->getQuery()->orders = null;
        $base->getQuery()->limit = null;
        $base->getQuery()->offset = null;

        return [
            'total' => (clone $base)->count(),
            'menunggu' => (clone $base)
                ->where('status', PembayaranInvoice::STATUS_MENUNGGU)
                ->count(),
            'diproses' => (clone $base)
                ->where('status', PembayaranInvoice::STATUS_PROSES)
                ->count(),
            'lunas' => (clone $base)
                ->where('status', PembayaranInvoice::STATUS_LUNAS)
                ->count(),
        ];
    }

    private function applyChannelFilter($query, $channel): void
    {
        $query->whereHas('registrasi', function ($q) use ($channel) {
            if ($channel === 'offline') {
                $q->where('channel_konsultasi', RegistrasiKunjungan::CHANNEL_OFFLINE);
                return;
            }

            if ($channel === 'online') {
                $q->where('channel_konsultasi', RegistrasiKunjungan::CHANNEL_ONLINE);
                return;
            }

            if (in_array($channel, ['tanpa_konsultasi', 'tanpa konsultasi'], true)) {
                $q->where('channel_konsultasi', RegistrasiKunjungan::CHANNEL_TIDAK_KONSULTASI);
            }
        });
    }

    private function formatInvoiceStatus($status): string
    {
        return match ((int) $status) {
            PembayaranInvoice::STATUS_MENUNGGU => 'Menunggu Pembayaran',
            PembayaranInvoice::STATUS_PROSES => 'Proses Pembayaran',
            PembayaranInvoice::STATUS_LUNAS => 'Lunas',
            PembayaranInvoice::STATUS_BATAL => 'Batal',
            default => 'Draft',
        };
    }

    private function formatDate($value)
    {
        if (!$value) return null;

        try {
            return Carbon::parse($value)->format('d M Y H:i');
        } catch (\Throwable $e) {
            return null;
        }
    }

    private function generateInvoiceNumber(RegistrasiKunjungan $registrasi): string
    {
        return 'INV-' . $registrasi->kode_registrasi;
    }

    private function onlyExistingColumns(string $table, array $data): array
    {
        return collect($data)
            ->filter(fn ($value, $column) => Schema::hasColumn($table, $column))
            ->all();
    }

    private function formatTime($value)
    {
        if (!$value) {
            return null;
        }

        try {
            return \Carbon\Carbon::parse($value)->format('H:i');
        } catch (\Throwable $e) {
            return null;
        }
    }

    private function hasConsultation($registrasi): bool
    {
        if (!$registrasi) {
            return false;
        }

        $channel = $registrasi->channel_konsultasi ?? null;

        if ($channel === null || $channel === '') {
            return false;
        }

        if (is_numeric($channel)) {
            return in_array((int) $channel, [
                1,
                2,
                3,
                4,
            ], true);
        }

        $channel = strtolower(trim((string) $channel));

        return in_array($channel, [
            'offline',
            'online',
            'dokter',
            'sppg',
            'spkk',
            'konsultasi_offline',
            'konsultasi_online',
        ], true);
    }


    private function formatChannel($channel): string
    {
        if ($channel === null || $channel === '') {
            return '-';
        }

        if (is_numeric($channel)) {
            return match ((int) $channel) {
                1 => 'Konsultasi Offline',
                2 => 'Konsultasi Online',
                3 => 'Konsultasi SPPG',
                4 => 'Konsultasi SPKK',
                default => '-',
            };
        }

        return match (strtolower(trim((string) $channel))) {
            'offline', 'dokter', 'konsultasi_offline' => 'Konsultasi Offline',
            'online', 'konsultasi_online' => 'Konsultasi Online',
            'sppg' => 'Konsultasi SPPG',
            'spkk' => 'Konsultasi SPKK',
            default => ucfirst((string) $channel),
        };
    }

    private function formatLayanan($registrasi): string
    {
        if (!$registrasi) {
            return '-';
        }

        $layanan = [];

        if ($this->hasConsultation($registrasi)) {
            $layanan[] = 'Konsultasi';
        }

        if ((int) ($registrasi->is_treatment ?? 0) === 1) {
            $layanan[] = 'Treatment';
        }

        if ((int) ($registrasi->is_penjualan ?? 0) === 1) {
            $layanan[] = 'Obat / Produk';
        }

        return count($layanan) ? implode(' + ', $layanan) : '-';
    }

    private function mapInvoiceStatusToKey($status): string
    {
        return match ((int) $status) {
            PembayaranInvoice::STATUS_MENUNGGU => 'menunggu',
            PembayaranInvoice::STATUS_PROSES => 'diproses',
            PembayaranInvoice::STATUS_LUNAS => 'lunas',
            PembayaranInvoice::STATUS_BATAL => 'batal',
            default => 'unknown',
        };
    }

    private function mapInvoiceStatusToLabel($status): string
    {
        return match ((int) $status) {
            PembayaranInvoice::STATUS_MENUNGGU => 'Menunggu',
            PembayaranInvoice::STATUS_PROSES => 'Diproses',
            PembayaranInvoice::STATUS_LUNAS => 'Lunas',
            PembayaranInvoice::STATUS_BATAL => 'Batal',
            default => 'Tidak Diketahui',
        };
    }

    private function mapRequestStatusToInvoiceStatus($status)
    {
        if ($status === null || $status === '') {
            return null;
        }

        if (is_numeric($status)) {
            return (int) $status;
        }

        return match (strtolower(trim((string) $status))) {
            'menunggu', 'waiting', 'pending' => PembayaranInvoice::STATUS_MENUNGGU,
            'diproses', 'proses', 'process', 'processing' => PembayaranInvoice::STATUS_PROSES,
            'lunas', 'paid' => PembayaranInvoice::STATUS_LUNAS,
            'batal', 'cancel', 'cancelled' => PembayaranInvoice::STATUS_BATAL,
            default => null,
        };
    }

    private function username(): string
    {
        return auth()->user()->username ?? auth()->user()->name ?? 'system';
    }
}
