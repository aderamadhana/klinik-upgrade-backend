<?php

namespace App\Http\Controllers\Api\Kasir;

use App\Http\Controllers\Controller;
use App\Models\Pembayaran\PembayaranDepositTreatment;
use App\Models\Pembayaran\PembayaranDepositTreatmentClaim;
use App\Models\Pembayaran\PembayaranInvoice;
use App\Models\Pembayaran\PembayaranInvoiceItem;
use App\Models\Pembayaran\PembayaranInvoiceMetode;
use App\Models\Pembayaran\PembayaranInvoiceSequence;
use App\Models\Registrasi\RegistrasiKunjungan;
use App\Models\Registrasi\RegistrasiTask;
use App\Services\Stock\StockTransactionService;
use App\Services\Pembayaran\VoucherFinalizerService;
use App\Services\Pembayaran\PaymentInvoiceItemSyncService;
use App\Services\Pembayaran\PaymentPatientService;
use App\Services\Pembayaran\PaymentSubtotalDiscountService;
use App\Services\Pembayaran\PaymentMemberPointService;
use App\Services\Pembayaran\PaymentNurseTreatmentMaterialService;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use Endroid\QrCode\Encoding\Encoding;
use Endroid\QrCode\ErrorCorrectionLevel;
use Endroid\QrCode\QrCode;
use Endroid\QrCode\RoundBlockSizeMode;
use Endroid\QrCode\Writer\SvgWriter;
use Throwable;

class PembayaranController extends Controller
{
    protected StockTransactionService $stockTransactionService;
    protected VoucherFinalizerService $voucherFinalizerService;
    protected PaymentInvoiceItemSyncService $invoiceItemSyncService;
    protected PaymentPatientService $paymentPatientService;
    protected PaymentSubtotalDiscountService $subtotalDiscountService;
    protected PaymentMemberPointService $memberPointService;
    protected PaymentNurseTreatmentMaterialService $nurseTreatmentMaterialService;
    protected array $existingColumnCache = [];
    protected ?string $cachedUsername = null;
    
    public function __construct(
        StockTransactionService $stockTransactionService,
        VoucherFinalizerService $voucherFinalizerService,
        PaymentInvoiceItemSyncService $invoiceItemSyncService,
        PaymentPatientService $paymentPatientService,
        PaymentSubtotalDiscountService $subtotalDiscountService,
        PaymentMemberPointService $memberPointService,
        PaymentNurseTreatmentMaterialService $nurseTreatmentMaterialService
    ) {
        $this->stockTransactionService = $stockTransactionService;
        $this->voucherFinalizerService = $voucherFinalizerService;
        $this->invoiceItemSyncService = $invoiceItemSyncService;
        $this->paymentPatientService = $paymentPatientService;
        $this->subtotalDiscountService = $subtotalDiscountService;
        $this->memberPointService = $memberPointService;
        $this->nurseTreatmentMaterialService = $nurseTreatmentMaterialService;
    }

    public function index(Request $request)
    {
        if ($this->shouldSyncPendingInvoicesOnList($request)) {
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

        $groupedItems = $this->buildGroupedPaymentListRows($items);

        return response()->json([
            'status' => true,
            'message' => 'Data pembayaran berhasil diambil',
            'rows' => $groupedItems,
            'total' => $groupedItems->count(),
            'total_invoice' => $rows->total(),
            'total_group' => $groupedItems->count(),
            'per_page' => $rows->perPage(),
            'current_page' => $rows->currentPage(),
            'last_page' => $rows->lastPage(),
            'from' => $rows->firstItem(),
            'to' => $rows->firstItem() ? ($rows->firstItem() + $groupedItems->count() - 1) : null,
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
        try {
            $invoice = DB::transaction(function () use ($registrasiId) {
                $registrasi = RegistrasiKunjungan::query()
                    ->with([
                        'pasien',
                        'tasks',
                        'treatmentDetails',
                        'penjualanDetails',
                    ])
                    ->active()
                    ->lockForUpdate()
                    ->findOrFail($registrasiId);

                return $this->generateInvoiceFromRegistrasi($registrasi, true);
            }, 3);

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
        } catch (Throwable $e) {
            report($e);

            return response()->json([
                'status' => false,
                'message' => 'Gagal membuat invoice',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    public function start($id)
    {
        try {
            $invoice = DB::transaction(function () use ($id) {
                $invoice = $this->resolveOrGenerateInvoice($id);

                if (!$invoice) {
                    throw ValidationException::withMessages([
                        'invoice' => 'Invoice tidak ditemukan atau belum bisa dibuat dari registrasi.',
                    ]);
                }

                $invoice = PembayaranInvoice::query()
                    ->whereKey($invoice->id)
                    ->where(function ($q) {
                        $q->whereNull('is_delete')->orWhere('is_delete', 0);
                    })
                    ->lockForUpdate()
                    ->firstOrFail();

                if ((int) $invoice->status === PembayaranInvoice::STATUS_LUNAS) {
                    return [
                        'invoice_id' => $invoice->id,
                        'next_task_id' => null,
                        'already_paid' => true,
                    ];
                }

                $registrasi = RegistrasiKunjungan::query()
                    ->with('tasks')
                    ->whereKey($invoice->registrasi_id)
                    ->lockForUpdate()
                    ->first();

                if (!$registrasi) {
                    throw ValidationException::withMessages([
                        'registrasi' => 'Data registrasi invoice tidak ditemukan.',
                    ]);
                }

                $task = $this->getPaymentTask($registrasi);

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

                return $invoice;
            }, 3);

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
        } catch (ValidationException $e) {
            return response()->json([
                'status' => false,
                'message' => collect($e->errors())->flatten()->first() ?: 'Validasi gagal',
                'errors' => $e->errors(),
            ], 422);
        } catch (Throwable $e) {
            report($e);

            return response()->json([
                'status' => false,
                'message' => 'Gagal memproses pembayaran',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    // public function finish(Request $request, $id)
    // {
    //     $validator = Validator::make($request->all(), [
    //         'metode' => 'nullable|array',
    //         'metode.*.metode_bayar_id' => 'nullable|integer',
    //         'metode.*.metode_bayar_nama' => 'required_with:metode|string|max:100',
    //         'metode.*.metode_bayar_tipe' => 'nullable|integer',
    //         'metode.*.nominal_dialokasikan' => 'required_with:metode|numeric|min:0',
    //         'metode.*.nominal_diterima' => 'nullable|numeric|min:0',
    //         'metode.*.no_referensi' => 'nullable|string|max:150',
    //         'metode.*.catatan' => 'nullable|string',
    //         'metode_pembayaran' => 'nullable|string|max:100',
    //         'jumlah_bayar' => 'nullable|numeric|min:0',
    //         'catatan_pembayaran' => 'nullable|string',
    //         'jenis_transaksi' => 'nullable|integer|in:0,1,2,3,4',
    //         'referensi_dokter_id' => 'nullable|integer',
    //         'deposit_expired_option_id' => 'nullable|integer',
    //         'deposit_expired_at' => 'nullable|date',
    //         'deposit_item_ids' => 'nullable|array',
    //         'deposit_item_ids.*' => 'nullable|integer',
    //         'deposit_treatment_item_ids' => 'nullable|array',
    //         'deposit_treatment_item_ids.*' => 'nullable|integer',
    //         'deposit_items' => 'nullable|array',
    //         'deposit_items.*.item_id' => 'nullable|integer',
    //         'deposit_items.*.pembayaran_item_id' => 'nullable|integer',
    //         'deposit_items.*.invoice_item_id' => 'nullable|integer',
    //         'deposit_items.*.qty' => 'nullable|numeric|min:0',
    //         'deposit_items.*.qty_deposit' => 'nullable|numeric|min:0',
    //         'deposit_treatment_items' => 'nullable|array',
    //         'deposit_treatment_items.*.item_id' => 'nullable|integer',
    //         'deposit_treatment_items.*.pembayaran_item_id' => 'nullable|integer',
    //         'deposit_treatment_items.*.invoice_item_id' => 'nullable|integer',
    //         'deposit_treatment_items.*.qty' => 'nullable|numeric|min:0',
    //         'deposit_treatment_items.*.qty_deposit' => 'nullable|numeric|min:0',
    //         'deposit_item_quantities' => 'nullable|array',
    //         'update_pasien_phone' => 'nullable|boolean',
    //         'pasien_no_hp_update' => 'nullable|string|max:30',
    //         'pasien_no_wa_update' => 'nullable|string|max:30',
    //         'pasien_no_telp_update' => 'nullable|string|max:30',
    //         'sumber_informasi_id' => 'nullable|integer',
    //         'sumber_kedatangan' => 'nullable|string|max:100',
    //         'promo_ids' => 'nullable|array',
    //         'promo_ids.*' => 'nullable|integer',
    //         'promos' => 'nullable|array',
    //         'selected_promos' => 'nullable|array',
    //         'applied_promos' => 'nullable|array',
    //         'promo_code' => 'nullable|string|max:100',
    //         'diskon_subtotal_tipe' => 'nullable',
    //         'diskon_subtotal_nilai' => 'nullable|numeric|min:0',
    //     ]);

    //     if ($validator->fails()) {
    //         return response()->json([
    //             'status' => false,
    //             'message' => 'Validasi gagal',
    //             'errors' => $validator->errors(),
    //         ], 422);
    //     }

    //     try {
    //         $result = DB::transaction(function () use ($request, $id) {
    //             $invoice = $this->resolveInvoiceForUpdate($id);

    //             if (!$invoice) {
    //                 throw ValidationException::withMessages([
    //                     'invoice' => 'Invoice tidak ditemukan.',
    //                 ]);
    //             }

    //             if ((int) $invoice->status === PembayaranInvoice::STATUS_LUNAS) {
    //                 throw ValidationException::withMessages([
    //                     'invoice' => 'Invoice sudah lunas.',
    //                 ]);
    //             }

    //             $registrasi = RegistrasiKunjungan::query()
    //                 ->with(['tasks'])
    //                 ->whereKey($invoice->registrasi_id)
    //                 ->where(function ($q) {
    //                     $q->whereNull('is_delete')->orWhere('is_delete', 0);
    //                 })
    //                 ->lockForUpdate()
    //                 ->first();

    //             if (!$registrasi) {
    //                 throw ValidationException::withMessages([
    //                     'registrasi' => 'Data registrasi invoice tidak ditemukan.',
    //                 ]);
    //             }

    //             $jenisTransaksi = (int) $request->input('jenis_transaksi', $invoice->jenis_transaksi ?? 0);
    //             $depositExpiredAt = $this->resolveDepositExpiredAt($request);

    //             $this->ensureLegacyInvoiceNumber($invoice, $registrasi, $jenisTransaksi);

    //             $registrasi->loadMissing(['treatmentDetails', 'penjualanDetails']);
    //             $this->syncInvoiceItemsFromRegistrasi($invoice, $registrasi);
    //             $this->syncInvoiceItemsFromRequest($request, $invoice);
    //             $this->refreshInvoiceTotalsFromItems($invoice);

    //             $invoice = PembayaranInvoice::query()
    //                 ->whereKey($invoice->id)
    //                 ->lockForUpdate()
    //                 ->firstOrFail();

    //             $invoice->load([
    //                 'items',
    //                 'metode',
    //                 'promos',
    //                 'depositClaims',
    //             ]);

    //             $this->updatePatientPhoneFromRequest($request, $invoice);
    //             $this->validateJenisTransaksiKhusus($request, $invoice, $jenisTransaksi, $depositExpiredAt);

    //             if ($jenisTransaksi === 4 && $this->shouldSplitDepositInvoice($request, $invoice)) {
    //                 return $this->finishSplitDepositInvoice($request, $invoice, $registrasi, $depositExpiredAt);
    //             }

    //             $this->syncJenisTransaksiToInvoiceItems($invoice, $jenisTransaksi);

    //             if ($jenisTransaksi !== 4) {
    //                 $this->processDepositClaimsFromInvoice($invoice, $registrasi);
    //             }

    //             $this->applySubtotalDiscountFromRequest($request, $invoice);
    //             $this->refreshInvoiceTotalsFromItems($invoice);

    //             $invoice = PembayaranInvoice::query()
    //                 ->whereKey($invoice->id)
    //                 ->lockForUpdate()
    //                 ->firstOrFail();
    //             $invoice->load(['items', 'metode', 'promos', 'depositClaims']);

    //             $this->voucherFinalizerService->applySelectedPromosFromRequest(
    //                 $invoice,
    //                 $request,
    //                 $this->username()
    //             );

    //             $invoice = PembayaranInvoice::query()
    //                 ->whereKey($invoice->id)
    //                 ->lockForUpdate()
    //                 ->firstOrFail();
    //             $invoice->load(['items', 'metode', 'promos', 'depositClaims']);

    //             $memberBeforeLedger = $this->snapshotPaymentMemberReward($invoice);

    //             $this->memberPointService->applyMemberBenefitToInvoice($invoice, $this->username());
    //             $this->refreshInvoiceTotalsFromItems($invoice);

    //             $invoice = PembayaranInvoice::query()
    //                 ->whereKey($invoice->id)
    //                 ->lockForUpdate()
    //                 ->firstOrFail();

    //             $invoice->load([
    //                 'items',
    //                 'metode',
    //                 'promos',
    //                 'depositClaims',
    //             ]);

    //             $this->nurseTreatmentMaterialService->syncFromInvoice($invoice, $registrasi, $this->username());

    //             $metode = $this->normalizeMetodePayload($request, $invoice);
    //             $totalBayar = collect($metode)->sum('nominal_dialokasikan');
    //             $grandTotal = (float) ($invoice->grand_total ?? 0);

    //             $this->validatePaymentAmount($grandTotal, $totalBayar);
    //             $this->replaceInvoiceMetode($invoice, $metode, $jenisTransaksi);

    //             if ($jenisTransaksi === 4) {
    //                 $selectedDepositItems = $this->resolveDepositItems($request, $invoice);
    //                 $selectedDepositItemIds = array_keys($selectedDepositItems);
    //                 $this->applyDepositTransactionRules($invoice, $request, $depositExpiredAt, $selectedDepositItemIds);
    //                 $this->generateDepositTreatmentRecords($invoice, $selectedDepositItems);
    //             }

    //             $this->processStockKeluarPembayaran($invoice, $registrasi);
    //             $this->createAccurateSyncPendingLog($invoice, $registrasi);

    //             $invoice->update($this->onlyExistingColumns('pembayaran_invoice', [
    //                 'tanggal_lunas' => now(),
    //                 'jenis_transaksi' => $jenisTransaksi,
    //                 'referensi_dokter_id' => $jenisTransaksi === 4
    //                     ? $request->input('referensi_dokter_id')
    //                     : ($request->input('referensi_dokter_id', $invoice->referensi_dokter_id ?? null)),
    //                 'deposit_expired_option_id' => $jenisTransaksi === 4
    //                     ? $request->input('deposit_expired_option_id')
    //                     : ($invoice->deposit_expired_option_id ?? null),
    //                 'deposit_expired_at' => $jenisTransaksi === 4
    //                     ? $depositExpiredAt
    //                     : ($invoice->deposit_expired_at ?? null),
    //                 'sumber_informasi_id' => $request->input('sumber_informasi_id', $invoice->sumber_informasi_id ?? null),
    //                 'sumber_kedatangan' => $request->input('sumber_kedatangan', $invoice->sumber_kedatangan ?? null),
    //                 'catatan' => $request->input('catatan_pembayaran', $invoice->catatan ?? null),
    //                 'grand_total' => $grandTotal,
    //                 'total_bayar' => $totalBayar,
    //                 'sisa_tagihan' => 0,
    //                 'total_kembalian' => max(0, $totalBayar - $grandTotal),
    //                 'status' => PembayaranInvoice::STATUS_LUNAS,
    //                 'updated_by' => $this->username(),
    //                 'updated_at' => now(),
    //             ]));

    //             $invoice->refresh();

    //             $this->memberPointService->processEarnedPointLedger($invoice, $this->username());

    //             $memberReward = $this->buildPaymentMemberRewardResponse($invoice, $memberBeforeLedger);

    //             $nextTask = $this->completePaymentTask($registrasi);

    //             return [
    //                 'invoice_id' => $invoice->id,
    //                 'next_task_id' => $nextTask?->id,
    //                 'already_paid' => false,
    //                 'member_reward' => $memberReward,
    //             ];
    //         }, 3);

    //         return $this->buildFinishSuccessResponse($result);
    //     } catch (ValidationException $e) {
    //         return response()->json([
    //             'status' => false,
    //             'message' => collect($e->errors())->flatten()->first() ?: 'Validasi gagal',
    //             'errors' => $e->errors(),
    //         ], 422);
    //     } catch (Throwable $e) {
    //         report($e);

    //         return response()->json([
    //             'status' => false,
    //             'message' => 'Gagal menyelesaikan pembayaran',
    //             'error' => $e->getMessage(),
    //         ], 500);
    //     }
    // }


    protected function buildFinishSuccessResponse(array $result)
    {
        $invoiceId = (int) ($result['invoice_id'] ?? 0);
        $nextTaskId = $result['next_task_id'] ?? null;
        $alreadyPaid = (bool) ($result['already_paid'] ?? false);
        $splitInvoiceIds = $result['split_invoice_ids'] ?? null;

        $message = $alreadyPaid
            ? 'Invoice sudah lunas.'
            : ($splitInvoiceIds
                ? ($nextTaskId
                    ? 'Pembayaran berhasil. Invoice umum dan deposit berhasil dibuat. Registrasi dilanjutkan ke task berikutnya.'
                    : 'Pembayaran berhasil. Invoice umum dan deposit berhasil dibuat. Registrasi selesai.')
                : ($nextTaskId
                    ? 'Pembayaran berhasil. Registrasi dilanjutkan ke task berikutnya.'
                    : 'Pembayaran berhasil. Registrasi selesai.'));

        try {
            $invoice = PembayaranInvoice::query()
                ->with([
                    'registrasi.pasien',
                    'registrasi.dokterAwal',
                    'registrasi.perawatAwal',
                    'registrasi.tasks',
                    'items',
                    'metode',
                    'promos',
                    'depositClaims',
                ])
                ->find($invoiceId);

            if ($invoice) {
                $data = $this->formatPaymentRow($invoice);
                $data['member_reward'] = $result['member_reward']
                    ?? $this->buildPaymentMemberRewardResponse($invoice);

                if ($splitInvoiceIds) {
                    $data['split_invoice_ids'] = $splitInvoiceIds;
                    $data['general_invoice_id'] = $result['general_invoice_id'] ?? null;
                    $data['deposit_invoice_id'] = $result['deposit_invoice_id'] ?? null;
                    $data['split_invoices'] = $this->formatSplitInvoicesForResponse($splitInvoiceIds);
                }

                return response()->json([
                    'status' => true,
                    'message' => $message,
                    'data' => $data,
                ]);
            }
        } catch (Throwable $e) {
            report($e);
        }

        $invoice = PembayaranInvoice::query()->find($invoiceId);

        $data = $invoice ? [
            'id' => $invoice->id,
            'invoice_id' => $invoice->id,
            'registrasi_id' => $invoice->registrasi_id,
            'no_invoice' => $invoice->no_invoice,
            'status' => $invoice->status,
            'status_key' => $this->mapInvoiceStatusToKey((int) ($invoice->status ?? 0)),
            'status_label' => $this->mapInvoiceStatusToLabel((int) ($invoice->status ?? 0)),
            'is_premier' => (int) ($invoice->is_premier ?? 0),
            'grand_total' => (float) ($invoice->grand_total ?? 0),
            'total_bayar' => (float) ($invoice->total_bayar ?? 0),
            'sisa_tagihan' => (float) ($invoice->sisa_tagihan ?? 0),
            'tanggal_lunas' => $invoice->tanggal_lunas,
        ] : [
            'id' => $invoiceId,
            'invoice_id' => $invoiceId,
            'status_key' => 'lunas',
            'status_label' => 'Lunas',
        ];

        $data['member_reward'] = $result['member_reward'] ?? null;

        if ($splitInvoiceIds) {
            $data['split_invoice_ids'] = $splitInvoiceIds;
            $data['general_invoice_id'] = $result['general_invoice_id'] ?? null;
            $data['deposit_invoice_id'] = $result['deposit_invoice_id'] ?? null;
        }

        return response()->json([
            'status' => true,
            'message' => $message,
            'data' => $data,
        ]);
    }


    protected function snapshotPaymentMemberReward(PembayaranInvoice $invoice, bool $lock = true): array
    {
        $pasienId = $this->resolveMemberRewardPasienId($invoice);
        $member = $this->resolveActivePasienMember($pasienId, $lock);

        if (!$member) {
            return [
                'member_id' => null,
                'member_no' => null,
                'member_tier_id' => null,
                'member_tier_nama' => null,
                'point_sisa' => 0,
                'total_point' => 0,
                'total_spending' => 0,
            ];
        }

        $tier = $this->resolveMemberRewardTier((int) ($member->member_tier_id ?? 0));

        return [
            'member_id' => (int) ($member->id ?? 0),
            'member_no' => $member->no_member ?? null,
            'member_tier_id' => (int) ($member->member_tier_id ?? 0),
            'member_tier_nama' => $tier?->nama ?? null,
            'point_sisa' => (float) ($member->point_sisa ?? 0),
            'total_point' => (float) ($member->total_point ?? 0),
            'total_spending' => (float) ($member->total_spending ?? 0),
        ];
    }

    protected function buildPaymentMemberRewardResponse(
        PembayaranInvoice $invoice,
        ?array $before = null,
        array $invoiceIds = []
    ): array {
        $invoiceIds = collect($invoiceIds)
            ->push((int) ($invoice->id ?? 0))
            ->filter(fn ($id) => (int) $id > 0)
            ->map(fn ($id) => (int) $id)
            ->unique()
            ->values()
            ->all();

        $invoiceRows = PembayaranInvoice::query()
            ->whereIn('id', $invoiceIds)
            ->get();

        $freshInvoice = $invoiceRows->firstWhere('id', (int) ($invoice->id ?? 0)) ?: $invoice;
        $pasienId = $this->resolveMemberRewardPasienId($freshInvoice);

        if ($pasienId <= 0 && $invoiceRows->isNotEmpty()) {
            foreach ($invoiceRows as $row) {
                $pasienId = $this->resolveMemberRewardPasienId($row);
                if ($pasienId > 0) {
                    break;
                }
            }
        }

        $before = $before ?? $this->snapshotPaymentMemberReward($freshInvoice, false);
        $member = $this->resolveActivePasienMember($pasienId);
        $currentTier = $member
            ? $this->resolveMemberRewardTier((int) ($member->member_tier_id ?? 0))
            : null;

        $previousTierId = (int) ($before['member_tier_id'] ?? 0);
        $currentTierId = (int) ($member->member_tier_id ?? 0);

        $memberCreated = empty($before['member_id']) && $member;
        $tierChanged = $previousTierId > 0
            && $currentTierId > 0
            && $previousTierId !== $currentTierId;

        $pasienLedger = $this->resolveLatestPaymentPointLedger($invoiceIds, $pasienId);
        $memberLedger = $this->resolveLatestMemberPointLedger($invoiceIds, $pasienId);

        $invoicePointEarned = (float) $invoiceRows->sum(fn ($row) => (float) ($row->point_earned ?? $row->poin ?? 0));
        $ledgerPointEarned = (float) ($pasienLedger['total_point_earned'] ?? 0);
        $memberLedgerPointEarned = (float) ($memberLedger['total_point_earned'] ?? 0);
        $pointEarned = (int) round(max($invoicePointEarned, $ledgerPointEarned, $memberLedgerPointEarned));

        $pointBalance = $member
            ? (float) ($member->point_sisa ?? 0)
            : (float) ($pasienLedger['saldo_setelah'] ?? 0);

        $totalPoint = $member
            ? (float) ($member->total_point ?? 0)
            : $pointBalance;

        $totalSpending = $member
            ? (float) ($member->total_spending ?? 0)
            : 0;

        $shouldShow = (bool) ($memberCreated || $tierChanged || $pointEarned > 0);

        return [
            'should_show' => $shouldShow,
            'member_created' => (bool) $memberCreated,
            'tier_changed' => (bool) $tierChanged,

            'pasien_id' => $pasienId,
            'invoice_id' => (int) ($freshInvoice->id ?? 0),
            'invoice_ids' => $invoiceIds,
            'no_invoice' => $freshInvoice->no_invoice ?? null,

            'member_id' => $member ? (int) ($member->id ?? 0) : null,
            'member_no' => $member->no_member ?? ($freshInvoice->member_no ?? null),

            'previous_tier_id' => $previousTierId ?: null,
            'previous_tier_nama' => $before['member_tier_nama'] ?? null,

            'current_tier_id' => $currentTierId ?: null,
            'current_tier_nama' => $currentTier?->nama ?? ($freshInvoice->member_tier_nama ?? null),

            'point_earned' => $pointEarned,
            'point_balance' => $pointBalance,
            'total_point' => $totalPoint,
            'total_spending' => $totalSpending,

            'ledger' => $pasienLedger,
            'member_ledger' => $memberLedger,
        ];
    }

    protected function resolveMemberRewardPasienId(PembayaranInvoice $invoice): int
    {
        $pasienId = (int) ($invoice->pasien_id ?? 0);

        if ($pasienId > 0) {
            return $pasienId;
        }

        if ($invoice->relationLoaded('registrasi') && $invoice->registrasi) {
            $pasienId = (int) ($invoice->registrasi->pasien_id ?? $invoice->registrasi->pasien_new_id ?? 0);

            if ($pasienId > 0) {
                return $pasienId;
            }
        }

        $registrasiId = (int) ($invoice->registrasi_id ?? 0);

        if ($registrasiId > 0 && Schema::hasTable('registrasi_kunjungan')) {
            $columns = ['id'];

            foreach (['pasien_id', 'pasien_new_id'] as $column) {
                if (Schema::hasColumn('registrasi_kunjungan', $column)) {
                    $columns[] = $column;
                }
            }

            $registrasi = DB::table('registrasi_kunjungan')
                ->where('id', $registrasiId)
                ->first($columns);

            return (int) ($registrasi->pasien_id ?? $registrasi->pasien_new_id ?? 0);
        }

        return 0;
    }

    protected function resolveActivePasienMember(int $pasienId, bool $lock = false): ?object
    {
        if ($pasienId <= 0 || !Schema::hasTable('pasien_member')) {
            return null;
        }

        $query = DB::table('pasien_member')
            ->where('pasien_id', $pasienId);

        if (Schema::hasColumn('pasien_member', 'is_delete')) {
            $query->where(function ($q) {
                $q->whereNull('is_delete')->orWhere('is_delete', 0);
            });
        }

        if (Schema::hasColumn('pasien_member', 'status')) {
            $query->where(function ($q) {
                $q->whereNull('status')->orWhere('status', 1);
            });
        }

        if ($lock) {
            $query->lockForUpdate();
        }

        return $query
            ->orderByDesc('id')
            ->first();
    }

    protected function resolveMemberRewardTier(int $tierId): ?object
    {
        if ($tierId <= 0 || !Schema::hasTable('master_member_tier')) {
            return null;
        }

        $query = DB::table('master_member_tier')
            ->where('id', $tierId);

        if (Schema::hasColumn('master_member_tier', 'is_delete')) {
            $query->where(function ($q) {
                $q->whereNull('is_delete')->orWhere('is_delete', 0);
            });
        }

        if (Schema::hasColumn('master_member_tier', 'is_active')) {
            $query->where(function ($q) {
                $q->whereNull('is_active')->orWhere('is_active', 1);
            });
        }

        return $query->first();
    }

    protected function resolveLatestPaymentPointLedger(array $invoiceIds, int $pasienId = 0): ?array
    {
        $invoiceIds = collect($invoiceIds)
            ->filter(fn ($id) => (int) $id > 0)
            ->map(fn ($id) => (int) $id)
            ->unique()
            ->values()
            ->all();

        if (count($invoiceIds) === 0 || !Schema::hasTable('pasien_poin_ledger')) {
            return null;
        }

        $query = DB::table('pasien_poin_ledger')
            ->whereIn('source_id', $invoiceIds);

        if (Schema::hasColumn('pasien_poin_ledger', 'source_table')) {
            $query->where('source_table', 'pembayaran_invoice');
        }

        if (Schema::hasColumn('pasien_poin_ledger', 'tipe_mutasi')) {
            $query->where('tipe_mutasi', 'earn');
        }

        if (Schema::hasColumn('pasien_poin_ledger', 'is_void')) {
            $query->where(function ($q) {
                $q->whereNull('is_void')->orWhere('is_void', 0);
            });
        }

        if ($pasienId > 0 && Schema::hasColumn('pasien_poin_ledger', 'pasien_id')) {
            $query->where('pasien_id', $pasienId);
        }

        $rows = $query
            ->orderByDesc('id')
            ->get();

        if ($rows->isEmpty()) {
            return null;
        }

        $latest = $rows->first();

        return [
            'id' => (int) ($latest->id ?? 0),
            'total_point_earned' => (int) $rows->sum(fn ($row) => (int) ($row->poin_masuk ?? 0)),
            'poin_masuk' => (int) ($latest->poin_masuk ?? 0),
            'poin_keluar' => (int) ($latest->poin_keluar ?? 0),
            'saldo_sebelum' => (int) ($latest->saldo_sebelum ?? 0),
            'saldo_setelah' => (int) ($latest->saldo_setelah ?? 0),
            'nominal_transaksi' => (float) ($latest->nominal_transaksi ?? 0),
            'tanggal' => $latest->tanggal ?? null,
            'keterangan' => $latest->keterangan ?? null,
        ];
    }

    protected function resolveLatestMemberPointLedger(array $invoiceIds, int $pasienId = 0): ?array
    {
        $invoiceIds = collect($invoiceIds)
            ->filter(fn ($id) => (int) $id > 0)
            ->map(fn ($id) => (int) $id)
            ->unique()
            ->values()
            ->all();

        if (count($invoiceIds) === 0 || !Schema::hasTable('member_point_ledger')) {
            return null;
        }

        $query = DB::table('member_point_ledger')
            ->whereIn('pembayaran_id', $invoiceIds);

        if (Schema::hasColumn('member_point_ledger', 'transaksi_type')) {
            $query->where('transaksi_type', 1);
        }

        if (Schema::hasColumn('member_point_ledger', 'is_void')) {
            $query->where(function ($q) {
                $q->whereNull('is_void')->orWhere('is_void', 0);
            });
        }

        if ($pasienId > 0 && Schema::hasColumn('member_point_ledger', 'pasien_id')) {
            $query->where('pasien_id', $pasienId);
        }

        $rows = $query
            ->orderByDesc('id')
            ->get();

        if ($rows->isEmpty()) {
            return null;
        }

        $latest = $rows->first();

        return [
            'id' => (int) ($latest->id ?? 0),
            'member_id' => (int) ($latest->member_id ?? 0),
            'total_point_earned' => (float) $rows->sum(fn ($row) => (float) ($row->point_masuk ?? 0)),
            'point_masuk' => (float) ($latest->point_masuk ?? 0),
            'point_keluar' => (float) ($latest->point_keluar ?? 0),
            'nominal_referensi' => (float) ($latest->nominal_referensi ?? 0),
            'tanggal_transaksi' => $latest->tanggal_transaksi ?? null,
            'catatan' => $latest->catatan ?? null,
        ];
    }


    protected function formatSplitInvoicesForResponse(array $splitInvoiceIds): array
    {
        $ids = collect($splitInvoiceIds)
            ->filter(fn ($id) => (int) $id > 0)
            ->map(fn ($id) => (int) $id)
            ->values()
            ->all();

        if (count($ids) === 0) {
            return [];
        }

        $rows = PembayaranInvoice::query()
            ->with([
                'registrasi.pasien',
                'registrasi.dokterAwal',
                'registrasi.perawatAwal',
                'registrasi.tasks',
                'items',
                'metode',
                'promos',
                'depositClaims',
            ])
            ->whereIn('id', $ids)
            ->get()
            ->keyBy('id');

        $result = [];

        foreach ($splitInvoiceIds as $key => $id) {
            $invoice = $rows->get((int) $id);
            if ($invoice) {
                $result[$key] = $this->formatPaymentRow($invoice);
            }
        }

        return $result;
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

        try {
            DB::transaction(function () use ($invoice) {
                $lockedInvoice = PembayaranInvoice::query()
                    ->whereKey($invoice->id)
                    ->where(function ($q) {
                        $q->whereNull('is_delete')->orWhere('is_delete', 0);
                    })
                    ->lockForUpdate()
                    ->firstOrFail();

                if ((int) $lockedInvoice->status === PembayaranInvoice::STATUS_LUNAS) {
                    throw ValidationException::withMessages([
                        'invoice' => 'Invoice lunas tidak bisa dihitung ulang.',
                    ]);
                }

                $this->recalculateInvoiceTotals($lockedInvoice);
            }, 3);

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
        } catch (ValidationException $e) {
            return response()->json([
                'status' => false,
                'message' => collect($e->errors())->flatten()->first() ?: 'Validasi gagal',
                'errors' => $e->errors(),
            ], 422);
        } catch (Throwable $e) {
            report($e);

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

        try {
            DB::transaction(function () use ($invoice) {
                $lockedInvoice = PembayaranInvoice::query()
                    ->whereKey($invoice->id)
                    ->where(function ($q) {
                        $q->whereNull('is_delete')->orWhere('is_delete', 0);
                    })
                    ->lockForUpdate()
                    ->firstOrFail();

                if ((int) $lockedInvoice->status === PembayaranInvoice::STATUS_LUNAS) {
                    throw ValidationException::withMessages([
                        'invoice' => 'Invoice lunas tidak bisa dibatalkan.',
                    ]);
                }

                $this->rollbackPaymentSideEffects($lockedInvoice, 'Invoice dibatalkan sebelum lunas.');
                $this->voucherFinalizerService->restoreVoucherAfterCancel(
                    $lockedInvoice,
                    $this->username()
                );

                $lockedInvoice->update($this->onlyExistingColumns('pembayaran_invoice', [
                    'status' => PembayaranInvoice::STATUS_BATAL,
                    'is_delete' => 1,
                    'deleted_by' => $this->username(),
                    'deleted_at' => now(),
                    'updated_by' => $this->username(),
                    'updated_at' => now(),
                ]));
            }, 3);

            return response()->json([
                'status' => true,
                'message' => 'Invoice berhasil dibatalkan',
            ]);
        } catch (ValidationException $e) {
            return response()->json([
                'status' => false,
                'message' => collect($e->errors())->flatten()->first() ?: 'Validasi gagal',
                'errors' => $e->errors(),
            ], 422);
        } catch (Throwable $e) {
            report($e);

            return response()->json([
                'status' => false,
                'message' => 'Gagal membatalkan invoice',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    public function printInvoice($id)
    {
        $invoice = $this->resolveInvoice($id);

        if (!$invoice) {
            return response('Invoice tidak ditemukan.', 404);
        }

        if ((int) $invoice->status !== PembayaranInvoice::STATUS_LUNAS) {
            return response('Invoice hanya bisa dicetak setelah pembayaran lunas.', 422);
        }

        $invoice->load([
            'registrasi.toko',
            'registrasi.pasien',
            'registrasi.dokterAwal',
            'registrasi.perawatAwal',
            'items.promos',
            'items.depositClaims',
            'metode',
            'promos',
            'depositClaims.depositTreatment',
        ]);

        return response()
            ->view('kasir.pembayaran.invoice-print', [
                'invoice' => $invoice,
                'registrasi' => $invoice->registrasi,
                'toko' => $invoice->registrasi?->toko,
                'pasien' => $invoice->registrasi?->pasien,
                'items' => $invoice->items
                    ->where('is_delete', 0)
                    ->values(),
                'metode' => $invoice->metode
                    ->where('is_delete', 0)
                    ->values(),
                'promos' => $invoice->promos
                    ->where('is_delete', 0)
                    ->values(),
                'depositClaims' => $invoice->depositClaims
                    ->where('is_delete', 0)
                    ->values(),
                'printedBy' => $this->username(),
                'qrDataUri' => $this->buildInvoiceQrDataUri($invoice),
            ])
            ->header('Content-Type', 'text/html; charset=UTF-8');
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
                        ->orWhere('channel_konsultasi', 2)
                        ->orWhere('is_konsultasi_online', 1)
                        ->orWhere('is_pembelian_online', 1);
                });
            } elseif ($channel === 'offline') {
                $q->where(function ($sub) {
                    $sub->whereNull('channel_konsultasi')
                        ->orWhere('channel_konsultasi', 0)
                        ->orWhere('channel_konsultasi', 1)
                        ->orWhere('channel_konsultasi', 'offline')
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

    protected function buildGroupedPaymentListRows($items)
    {
        return collect($items)
            ->groupBy(fn ($row) => $this->resolvePaymentGroupKey($row))
            ->map(fn ($groupRows) => $this->formatPaymentGroupRow($groupRows->values()))
            ->sortByDesc(fn ($row) => $row['tanggal_lunas'] ?? $row['tanggal_invoice'] ?? $row['created_at'] ?? '')
            ->values();
    }

    protected function resolvePaymentGroupKey(array $row): string
    {
        $baseNo = $this->resolveInvoiceBaseNoFromRow($row);

        if ($baseNo !== '') {
            return 'invoice_base:' . $baseNo;
        }

        $registrasiId = (int) ($row['registrasi_id'] ?? 0);
        if ($registrasiId > 0) {
            return 'registrasi:' . $registrasiId;
        }

        return 'invoice:' . ($row['invoice_id'] ?? $row['id'] ?? $row['no_invoice'] ?? uniqid('', true));
    }

    protected function resolveInvoiceBaseNoFromRow(array $row): string
    {
        $baseNo = trim((string) ($row['invoice_base_no'] ?? $row['invoice_group_no'] ?? ''));

        if ($baseNo !== '') {
            return $this->normalizeInvoiceNumberWithoutTransactionSuffix($baseNo);
        }

        $invoiceNo = trim((string) ($row['no_invoice'] ?? $row['nomor_invoice'] ?? $row['invoice_number'] ?? ''));

        if ($invoiceNo === '') {
            return '';
        }

        return $this->normalizeInvoiceNumberWithoutTransactionSuffix($invoiceNo);
    }

    protected function formatPaymentGroupRow($groupRows): array
    {
        $rows = collect($groupRows)
            ->sortBy(function ($row) {
                $suffix = strtoupper((string) ($row['invoice_suffix'] ?? $this->resolveInvoiceSuffixFromNumber($row['no_invoice'] ?? '')));

                return match ($suffix) {
                    'U' => 1,
                    'D' => 2,
                    'F' => 3,
                    'E' => 4,
                    'O' => 5,
                    default => 9,
                };
            })
            ->values();

        $primary = $rows->first() ?? [];
        $baseNo = $this->resolveInvoiceBaseNoFromRow($primary);
        $grandTotal = (float) $rows->sum(fn ($row) => (float) ($row['grand_total'] ?? $row['total_tagihan'] ?? 0));
        $totalBayar = (float) $rows->sum(fn ($row) => (float) ($row['total_bayar'] ?? 0));
        $subtotalProduk = (float) $rows->sum(fn ($row) => (float) ($row['subtotal_produk'] ?? $row['subtotal_obat'] ?? 0));
        $subtotalTreatment = (float) $rows->sum(fn ($row) => (float) ($row['subtotal_treatment'] ?? 0));
        $subtotalKonsultasi = (float) $rows->sum(fn ($row) => (float) ($row['subtotal_konsultasi'] ?? 0));
        $status = $this->resolvePaymentGroupStatus($rows);
        $layanan = $this->buildPaymentGroupLayananLabel($rows, $subtotalProduk, $subtotalTreatment, $subtotalKonsultasi);
        $metodePembayaran = $rows
            ->pluck('metode_pembayaran')
            ->filter()
            ->flatMap(fn ($value) => array_map('trim', explode(',', (string) $value)))
            ->filter()
            ->unique()
            ->values()
            ->implode(', ');

        $invoiceChildren = $rows->map(function ($row) {
            $invoiceNo = (string) ($row['no_invoice'] ?? $row['nomor_invoice'] ?? $row['invoice_number'] ?? '');
            $suffix = strtoupper((string) ($row['invoice_suffix'] ?? $this->resolveInvoiceSuffixFromNumber($invoiceNo)));

            return [
                'id' => $row['id'] ?? $row['invoice_id'] ?? null,
                'invoice_id' => $row['invoice_id'] ?? $row['id'] ?? null,
                'no_invoice' => $invoiceNo,
                'invoice_suffix' => $suffix,
                'label' => $this->resolveInvoiceChildLabel($suffix),
                'jenis_transaksi' => (int) ($row['jenis_transaksi'] ?? 0),
                'grand_total' => (float) ($row['grand_total'] ?? $row['total_tagihan'] ?? 0),
                'total_bayar' => (float) ($row['total_bayar'] ?? 0),
                'status' => (int) ($row['status'] ?? 0),
                'status_key' => $row['status_key'] ?? null,
                'status_label' => $row['status_label'] ?? null,
                'subtotal_produk' => (float) ($row['subtotal_produk'] ?? $row['subtotal_obat'] ?? 0),
                'subtotal_treatment' => (float) ($row['subtotal_treatment'] ?? 0),
                'subtotal_konsultasi' => (float) ($row['subtotal_konsultasi'] ?? 0),
            ];
        })->values();

        $group = $primary;
        $group['id'] = $primary['id'] ?? $primary['invoice_id'] ?? null;
        $group['invoice_id'] = $primary['invoice_id'] ?? $primary['id'] ?? null;
        $group['pembayaran_id'] = $group['invoice_id'];
        $group['invoice_base_no'] = $baseNo;
        $group['invoice_group_no'] = $baseNo;
        $group['no_invoice'] = $baseNo !== '' ? $baseNo : ($primary['no_invoice'] ?? null);
        $group['nomor_invoice'] = $group['no_invoice'];
        $group['invoice_number'] = $group['no_invoice'];
        $group['is_grouped_invoice'] = $rows->count() > 1;
        $group['invoice_count'] = $rows->count();
        $group['invoice_children'] = $invoiceChildren;
        $group['invoices'] = $invoiceChildren;
        $group['child_invoice_ids'] = $invoiceChildren->pluck('invoice_id')->filter()->values()->all();
        $group['subtotal_obat'] = $subtotalProduk;
        $group['subtotal_produk'] = $subtotalProduk;
        $group['subtotal_treatment'] = $subtotalTreatment;
        $group['subtotal_konsultasi'] = $subtotalKonsultasi;
        $group['total_tagihan'] = $grandTotal;
        $group['grand_total'] = $grandTotal;
        $group['total_pembayaran'] = $grandTotal;
        $group['total_bayar'] = $totalBayar;
        $group['sisa_tagihan'] = max($grandTotal - $totalBayar, 0);
        $group['status'] = $status['status'];
        $group['status_key'] = $status['status_key'];
        $group['status_label'] = $status['status_label'];
        $group['status_pembayaran_key'] = $status['status_key'];
        $group['status_pembayaran'] = $status['status_label'];
        $group['status_pembayaran_label'] = $status['status_label'];
        $group['metode_pembayaran'] = $metodePembayaran !== '' ? $metodePembayaran : ($primary['metode_pembayaran'] ?? null);
        $group['layanan_label'] = $layanan;
        $group['ada_treatment'] = $subtotalTreatment > 0;
        $group['ada_penjualan'] = $subtotalProduk > 0;
        $group['is_treatment'] = $subtotalTreatment > 0;
        $group['is_penjualan'] = $subtotalProduk > 0;
        $group['can_process_pembayaran'] = $rows->contains(fn ($row) => (bool) ($row['can_process_pembayaran'] ?? false));

        return $group;
    }

    protected function resolvePaymentGroupStatus($rows): array
    {
        $statuses = collect($rows)->pluck('status')->map(fn ($status) => (int) $status)->values();

        if ($statuses->contains(PembayaranInvoice::STATUS_PROSES)) {
            return [
                'status' => PembayaranInvoice::STATUS_PROSES,
                'status_key' => $this->mapInvoiceStatusToKey(PembayaranInvoice::STATUS_PROSES),
                'status_label' => $this->mapInvoiceStatusToLabel(PembayaranInvoice::STATUS_PROSES),
            ];
        }

        if ($statuses->contains(PembayaranInvoice::STATUS_MENUNGGU)) {
            return [
                'status' => PembayaranInvoice::STATUS_MENUNGGU,
                'status_key' => $this->mapInvoiceStatusToKey(PembayaranInvoice::STATUS_MENUNGGU),
                'status_label' => $this->mapInvoiceStatusToLabel(PembayaranInvoice::STATUS_MENUNGGU),
            ];
        }

        return [
            'status' => PembayaranInvoice::STATUS_LUNAS,
            'status_key' => $this->mapInvoiceStatusToKey(PembayaranInvoice::STATUS_LUNAS),
            'status_label' => $this->mapInvoiceStatusToLabel(PembayaranInvoice::STATUS_LUNAS),
        ];
    }

    protected function buildPaymentGroupLayananLabel($rows, float $subtotalProduk, float $subtotalTreatment, float $subtotalKonsultasi): string
    {
        $hasConsultation = $subtotalKonsultasi > 0 || $rows->contains(fn ($row) => (bool) ($row['ada_konsultasi'] ?? false));
        $hasTreatment = $subtotalTreatment > 0;
        $hasSales = $subtotalProduk > 0;
        $hasDeposit = $rows->contains(fn ($row) => (int) ($row['jenis_transaksi'] ?? 0) === 4 || strtoupper((string) ($row['invoice_suffix'] ?? $this->resolveInvoiceSuffixFromNumber($row['no_invoice'] ?? ''))) === 'D');

        $labels = [];

        if ($hasConsultation) {
            $labels[] = 'Konsultasi';
        }
        if ($hasTreatment) {
            $labels[] = $hasDeposit ? 'Treatment + Deposit' : 'Treatment';
        }
        if ($hasSales) {
            $labels[] = 'Penjualan';
        }

        return count($labels) > 0 ? implode(' + ', $labels) : '-';
    }

    protected function resolveInvoiceSuffixFromNumber(?string $invoiceNumber): string
    {
        $invoiceNumber = strtoupper(trim((string) $invoiceNumber));

        if (preg_match('/-([A-Z])$/', $invoiceNumber, $matches)) {
            return $matches[1];
        }

        return '';
    }

    protected function resolveInvoiceChildLabel(string $suffix): string
    {
        return match (strtoupper($suffix)) {
            'U' => 'Umum',
            'D' => 'Deposit',
            'F' => 'Faskar',
            'E' => 'EliteGlowbal',
            'O' => 'Owner',
            default => 'Invoice',
        };
    }

    protected function buildSummaryFast($query): array
    {
        $summaryQuery = clone $query;

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
            'total' => $totalInvoice,
            'menunggu' => $totalMenunggu,
            'diproses' => $totalDiproses,
            'lunas' => $totalLunas,
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
        $konsultasiMeta = $this->resolveKonsultasiMeta($registrasi);
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
        $subtotalProduk = $this->invoiceSubtotalProduk($invoice);

        return [
            'id' => $invoice->id,
            'invoice_id' => $invoice->id,
            'pembayaran_id' => $invoice->id,
            'registrasi_id' => $invoice->registrasi_id,
            'no_invoice' => $invoice->no_invoice,
            'nomor_invoice' => $invoice->no_invoice,
            'invoice_number' => $invoice->no_invoice,
            'channel_konsultasi' => $konsultasiMeta['channel'],
            'channel_konsultasi_key' => $konsultasiMeta['channel_key'],
            'channel_konsultasi_label' => $konsultasiMeta['channel_label'],
            'konsultasi_source_code' => $konsultasiMeta['source_code'],
            'konsultasi_source_name' => $konsultasiMeta['source_name'],
            'jenis_konsultasi_label' => $konsultasiMeta['jenis_label'],
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
            'subtotal_obat' => $subtotalProduk,
            'subtotal_produk' => $subtotalProduk,
            'subtotal_treatment' => (float) ($invoice->subtotal_treatment ?? 0),
            'subtotal_konsultasi' => (float) ($invoice->subtotal_konsultasi ?? 0),
            'total_tagihan' => $grandTotal,
            'grand_total' => $grandTotal,
            'total_pembayaran' => $grandTotal,
            'total_bayar' => $totalBayar,
            'sisa_tagihan' => max($grandTotal - $totalBayar, 0),
            'status' => (int) $invoice->status,
            'status_key' => $statusKey,
            'status_label' => $statusLabel,
            'status_pembayaran_key' => $statusKey,
            'status_pembayaran' => $statusLabel,
            'status_pembayaran_label' => $statusLabel,
            'metode' => $invoice->metode?->where('is_delete', 0)->values() ?? [],
            'metode_pembayaran' => $metodePembayaran,
            'jenis_transaksi' => (int) ($invoice->jenis_transaksi ?? 0),
            'is_premier' => (int) ($invoice->is_premier ?? 0),
            'referensi_dokter_id' => $invoice->referensi_dokter_id ?? null,
            'deposit_expired_option_id' => $invoice->deposit_expired_option_id ?? null,
            'deposit_expired_at' => $invoice->deposit_expired_at ?? null,
            'sumber_informasi_id' => $invoice->sumber_informasi_id ?? null,
            'sumber_kedatangan' => $invoice->sumber_kedatangan ?? null,
            'channel_konsultasi' => $registrasi?->channel_konsultasi ?? null,
            'ada_konsultasi' => $this->hasRegistrasiConsultation($registrasi),
            'ada_treatment' => (float) ($invoice->subtotal_treatment ?? 0) > 0,
            'ada_penjualan' => $subtotalProduk > 0,
            'is_treatment' => (float) ($invoice->subtotal_treatment ?? 0) > 0,
            'is_penjualan' => $subtotalProduk > 0,
            'layanan_label' => $layananLabel,
            'can_process_pembayaran' => in_array($statusKey, ['menunggu', 'proses'], true),
        ];
    }

    protected function formatPaymentRow(PembayaranInvoice $invoice): array
    {
        $registrasi = $invoice->registrasi;
        $konsultasiMeta = $this->resolveKonsultasiMeta($registrasi);
        $pasien = $registrasi?->pasien;
        $subtotalProduk = $this->invoiceSubtotalProduk($invoice);
        $items = $invoice->items?->where('is_delete', 0)->values() ?? collect();
        $metode = $invoice->metode?->where('is_delete', 0)->values() ?? collect();
        $promo = $invoice->promos?->where('is_delete', 0)->values() ?? collect();
        $tanggalKunjungan = $registrasi?->tanggal_kunjungan
            ?? $registrasi?->tanggal
            ?? $invoice->tanggal_invoice;
        $tanggalKunjunganDate = $tanggalKunjungan
            ? Carbon::parse($tanggalKunjungan)->toDateString()
            : null;
        $tanggalInvoiceDate = $invoice->tanggal_invoice
            ? Carbon::parse($invoice->tanggal_invoice)->toDateString()
            : null;
        $voucherLabel = $promo->isNotEmpty()
            ? $promo->pluck('nama_voucher')->filter()->implode(', ')
            : null;

        return [
            'id' => $invoice->id,
            'invoice_id' => $invoice->id,
            'registrasi_id' => $invoice->registrasi_id,
            'no_invoice' => $invoice->no_invoice,
            'nomor_invoice' => $invoice->no_invoice,
            'invoice_number' => $invoice->no_invoice,
            'channel_konsultasi' => $konsultasiMeta['channel'],
            'channel_konsultasi_key' => $konsultasiMeta['channel_key'],
            'channel_konsultasi_label' => $konsultasiMeta['channel_label'],
            'konsultasi_source_code' => $konsultasiMeta['source_code'],
            'konsultasi_source_name' => $konsultasiMeta['source_name'],
            'jenis_konsultasi_label' => $konsultasiMeta['jenis_label'],
            'kode_registrasi' => $invoice->kode_registrasi,
            'nomor_kunjungan' => $invoice->kode_registrasi,
            'tanggal_invoice' => $invoice->tanggal_invoice,
            'tanggal_invoice_date' => $tanggalInvoiceDate,
            'tanggal_lunas' => $invoice->tanggal_lunas,
            'tanggal_kunjungan' => $tanggalKunjunganDate,
            'tanggal' => $tanggalKunjunganDate,
            'registered_at' => $registrasi?->registered_at ?? null,
            'toko_id' => $invoice->toko_id,
            'pasien_id' => $pasien?->id ?? $invoice->pasien_id,
            'pasien_nama' => $pasien?->nama ?? $invoice->nama_pasien ?? null,
            'nama_pasien' => $pasien?->nama ?? $invoice->nama_pasien ?? null,
            'no_rm' => $pasien?->no_rm ?? null,
            'pasien_no_rm' => $pasien?->no_rm ?? null,
            'no_hp' => $pasien?->no_hp ?? $pasien?->no_wa ?? null,
            'pasien_no_hp' => $pasien?->no_hp ?? $pasien?->no_wa ?? null,
            'pasien_no_wa' => $pasien?->no_wa ?? null,
            'pasien_no_telp' => $pasien?->no_telp ?? null,
            'member_id' => $invoice->member_id ?? null,
            'member_no' => $invoice->member_no ?? null,
            'member_tier_id' => $invoice->member_tier_id ?? null,
            'member_tier_nama' => $invoice->member_tier_nama ?? null,
            'point_earned' => (float) ($invoice->point_earned ?? 0),
            'poin' => (float) ($invoice->poin ?? $invoice->point_earned ?? 0),
            'diskon_member_amount' => (float) ($invoice->diskon_member_amount ?? 0),
            'pasien' => $pasien,
            'dokter_awal' => $registrasi?->dokterAwal,
            'perawat_awal' => $registrasi?->perawatAwal,
            'dokter_id' => $registrasi?->dokter_awal_id ?? $invoice->dokter_id ?? null,
            'dokter_nama' => $registrasi?->dokterAwal?->nama ?? null,
            'perawat_id' => $registrasi?->perawat_awal_id ?? null,
            'perawat_nama' => $registrasi?->perawatAwal?->nama ?? null,
            'items' => $items,
            'metode' => $metode,
            'promo' => $promo,
            'promos' => $promo,
            'voucher' => $promo,
            'voucher_list' => $promo,
            'voucher_label' => $voucherLabel,
            'voucher_nama' => $voucherLabel,
            'deposit_claims' => $invoice->depositClaims?->where('is_delete', 0)->values() ?? [],
            'subtotal_obat' => $subtotalProduk,
            'subtotal_produk' => $subtotalProduk,
            'subtotal_treatment' => (float) ($invoice->subtotal_treatment ?? 0),
            'subtotal_konsultasi' => (float) ($invoice->subtotal_konsultasi ?? 0),
            'subtotal' => (float) ($invoice->subtotal ?? 0),
            'diskon_subtotal' => (float) ($invoice->diskon_subtotal ?? $invoice->diskon_subtotal_amount ?? 0),
            'diskon_promo' => (float) ($invoice->diskon_promo ?? $invoice->total_promo ?? 0),
            'total_promo' => (float) ($invoice->total_promo ?? 0),
            'diskon_member_amount' => (float) ($invoice->diskon_member_amount ?? 0),
            'point_earned' => (float) ($invoice->point_earned ?? 0),
            'point_redeemed' => (float) ($invoice->point_redeemed ?? 0),
            'point_redeem_value' => (float) ($invoice->point_redeem_value ?? 0),
            'poin' => (float) ($invoice->poin ?? $invoice->point_earned ?? 0),
            'grand_total' => (float) ($invoice->grand_total ?? 0),
            'total_bayar' => (float) ($invoice->total_bayar ?? 0),
            'sisa_tagihan' => max((float) ($invoice->grand_total ?? 0) - (float) ($invoice->total_bayar ?? 0), 0),
            'total_kembalian' => (float) ($invoice->total_kembalian ?? 0),
            'status' => (int) $invoice->status,
            'status_key' => $this->mapInvoiceStatusToKey($invoice->status),
            'status_label' => $this->mapInvoiceStatusToLabel($invoice->status),
            'jenis_transaksi' => (int) ($invoice->jenis_transaksi ?? 0),
            'is_premier' => (int) ($invoice->is_premier ?? 0),
            'referensi_dokter_id' => $invoice->referensi_dokter_id ?? null,
            'deposit_expired_option_id' => $invoice->deposit_expired_option_id ?? null,
            'deposit_expired_at' => $invoice->deposit_expired_at ?? null,
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
        $hasSales = $this->invoiceSubtotalProduk($invoice) > 0;

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
            return DB::transaction(function () use ($registrasi) {
                $lockedRegistrasi = RegistrasiKunjungan::query()
                    ->with([
                        'pasien',
                        'tasks',
                        'treatmentDetails',
                        'penjualanDetails',
                    ])
                    ->whereKey($registrasi->id)
                    ->lockForUpdate()
                    ->firstOrFail();

                return $this->generateInvoiceFromRegistrasi($lockedRegistrasi, true);
            }, 3);
        } catch (Throwable $e) {
            Log::error('Gagal membuat invoice otomatis saat pembayaran', [
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

    protected function resolveInvoiceForUpdate($id): ?PembayaranInvoice
    {
        return PembayaranInvoice::query()
            ->where(function ($q) use ($id) {
                $q->where('id', $id)
                    ->orWhere('registrasi_id', $id);
            })
            ->where(function ($q) {
                $q->whereNull('is_delete')
                    ->orWhere('is_delete', 0);
            })
            ->orderByDesc('id')
            ->lockForUpdate()
            ->first();
    }

    protected function validateJenisTransaksiKhusus(Request $request, PembayaranInvoice $invoice, int $jenisTransaksi, ?string $depositExpiredAt = null): void
    {
        if ($jenisTransaksi !== 0 && trim((string) $request->input('catatan_pembayaran')) === '') {
            throw ValidationException::withMessages([
                'catatan_pembayaran' => 'Catatan wajib diisi untuk transaksi khusus.',
            ]);
        }

        if ($jenisTransaksi !== 4) {
            return;
        }

        if (!$request->filled('referensi_dokter_id')) {
            throw ValidationException::withMessages([
                'referensi_dokter_id' => 'Referensi dokter wajib diisi untuk transaksi deposit.',
            ]);
        }

        if (!$request->filled('deposit_expired_option_id') && !$request->filled('deposit_expired_at')) {
            throw ValidationException::withMessages([
                'deposit_expired_at' => 'Masa berlaku deposit wajib diisi.',
            ]);
        }

        if (!$depositExpiredAt) {
            throw ValidationException::withMessages([
                'deposit_expired_at' => 'Tanggal expired deposit tidak valid.',
            ]);
        }

        $activeItems = $invoice->items
            ? $invoice->items->filter(fn ($item) => (int) ($item->is_delete ?? 0) === 0)
            : collect();

        // Produk/obat tetap boleh ada pada transaksi deposit.
        // Produk diproses sebagai penjualan normal, sedangkan deposit hanya dibuat
        // untuk treatment yang dipilih dari deposit_item_ids.
        $hasTreatment = $activeItems->contains(fn ($item) => $this->isTreatmentItem($item));

        if (!$hasTreatment) {
            throw ValidationException::withMessages([
                'items' => 'Jenis transaksi deposit hanya bisa dipilih jika invoice memiliki minimal satu treatment.',
            ]);
        }

        $selectedDepositItemIds = $this->resolveDepositItemIds($request, $invoice);
        if (count($selectedDepositItemIds) === 0) {
            throw ValidationException::withMessages([
                'deposit_item_ids' => 'Pilih minimal satu treatment yang akan dijadikan deposit.',
            ]);
        }

        $validTreatmentItemIds = $activeItems
            ->filter(fn ($item) => $this->isTreatmentItem($item))
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->all();

        $invalid = array_values(array_diff($selectedDepositItemIds, $validTreatmentItemIds));
        if (count($invalid) > 0) {
            throw ValidationException::withMessages([
                'deposit_item_ids' => 'Pilihan treatment deposit tidak valid untuk invoice ini.',
            ]);
        }
    }

    protected function shouldSplitDepositInvoice(Request $request, PembayaranInvoice $invoice): bool
    {
        $selectedDepositItems = $this->resolveDepositItems($request, $invoice);

        if (count($selectedDepositItems) === 0) {
            return false;
        }

        $items = $this->activeInvoiceItemsForSplit($invoice);

        foreach ($items as $item) {
            $itemId = (int) ($item->id ?? 0);

            if (!$this->isTreatmentItem($item)) {
                return true;
            }

            if (!array_key_exists($itemId, $selectedDepositItems)) {
                return true;
            }

            $invoiceQty = (float) ($item->qty ?? $item->jumlah ?? 0);
            if ($invoiceQty <= 0) {
                $invoiceQty = 1;
            }

            $depositQty = (float) ($selectedDepositItems[$itemId] ?? 0);
            if ($depositQty > 0 && $depositQty < $invoiceQty) {
                return true;
            }
        }

        return false;
    }

    protected function finishSplitDepositInvoice(
        Request $request,
        PembayaranInvoice $depositInvoice,
        RegistrasiKunjungan $registrasi,
        ?string $depositExpiredAt,
        ?array &$trace = null
    ): array {
        $splitTrace = function (string $label, array $context = []) use (&$trace): void {
            if (is_array($trace)) {
                $this->markPaymentFinishTrace($trace, 'splitDeposit.' . $label, $context);
            }
        };

        $splitTrace('started', [
            'deposit_invoice_id' => $depositInvoice->id,
            'registrasi_id' => $registrasi->id,
        ]);

        $selectedDepositItems = $this->resolveDepositItems($request, $depositInvoice);
        $splitTrace('resolveDepositItems.finished', [
            'selected_count' => count($selectedDepositItems),
            'selected_item_ids' => array_keys($selectedDepositItems),
        ]);

        if (count($selectedDepositItems) === 0) {
            throw ValidationException::withMessages([
                'deposit_item_ids' => 'Pilih minimal satu treatment yang akan dijadikan deposit.',
            ]);
        }

        $generalInvoice = $this->resolveOrCreateGeneralInvoiceForDepositSplit($depositInvoice, $registrasi);
        $splitTrace('resolveOrCreateGeneralInvoiceForDepositSplit.finished', [
            'general_invoice_id' => $generalInvoice->id,
            'deposit_invoice_id' => $depositInvoice->id,
            'general_no_invoice' => $generalInvoice->no_invoice,
            'deposit_no_invoice' => $depositInvoice->no_invoice,
        ]);

        $this->splitDepositInvoiceItems(
            $depositInvoice,
            $generalInvoice,
            $selectedDepositItems
        );
        $splitTrace('splitDepositInvoiceItems.finished');

        $this->refreshInvoiceTotalsFromItems($depositInvoice);
        $splitTrace('refreshInvoiceTotalsFromItems.deposit.1.finished');

        $this->refreshInvoiceTotalsFromItems($generalInvoice);
        $splitTrace('refreshInvoiceTotalsFromItems.general.1.finished');

        $depositInvoice = PembayaranInvoice::query()
            ->whereKey($depositInvoice->id)
            ->lockForUpdate()
            ->firstOrFail();

        $generalInvoice = PembayaranInvoice::query()
            ->whereKey($generalInvoice->id)
            ->lockForUpdate()
            ->firstOrFail();

        $depositInvoice->load(['items', 'metode', 'promos', 'depositClaims']);
        $generalInvoice->load(['items', 'metode', 'promos', 'depositClaims']);
        $splitTrace('reload.afterSplit.finished', [
            'deposit_item_count' => $depositInvoice->items?->count(),
            'general_item_count' => $generalInvoice->items?->count(),
            'deposit_grand_total' => (float) ($depositInvoice->grand_total ?? 0),
            'general_grand_total' => (float) ($generalInvoice->grand_total ?? 0),
        ]);

        $this->applyDepositTransactionRules(
            $depositInvoice,
            $request,
            $depositExpiredAt,
            array_keys($selectedDepositItems)
        );
        $splitTrace('applyDepositTransactionRules.finished');

        $this->syncJenisTransaksiToInvoiceItems($generalInvoice, 0);
        $splitTrace('syncJenisTransaksiToInvoiceItems.general.finished');

        if ($this->hasPromoPayloadForSplitDeposit($request)) {
            $this->applyDiscountsAndPromosForSplitDepositInvoices(
                $request,
                $generalInvoice,
                $depositInvoice
            );
            $splitTrace('applyDiscountsAndPromosForSplitDepositInvoices.finished', [
                'has_promo_payload' => true,
            ]);
        } else {
            $this->resetSplitInvoiceDiscountsAndPromosLite($generalInvoice);
            $this->resetSplitInvoiceDiscountsAndPromosLite($depositInvoice);
            $splitTrace('applyDiscountsAndPromosForSplitDepositInvoices.skippedAndReset', [
                'has_promo_payload' => false,
            ]);
        }

        $depositInvoice = PembayaranInvoice::query()
            ->whereKey($depositInvoice->id)
            ->lockForUpdate()
            ->firstOrFail();

        $generalInvoice = PembayaranInvoice::query()
            ->whereKey($generalInvoice->id)
            ->lockForUpdate()
            ->firstOrFail();

        $depositInvoice->load(['items', 'metode', 'promos', 'depositClaims']);
        $generalInvoice->load(['items', 'metode', 'promos', 'depositClaims']);
        $splitTrace('reload.afterDiscount.finished', [
            'deposit_item_count' => $depositInvoice->items?->count(),
            'general_item_count' => $generalInvoice->items?->count(),
        ]);

        $this->generateDepositTreatmentRecords($depositInvoice, $selectedDepositItems);
        $splitTrace('generateDepositTreatmentRecords.finished');

        $this->refreshInvoiceTotalsFromItems($depositInvoice);
        $splitTrace('refreshInvoiceTotalsFromItems.deposit.2.finished');

        $this->refreshInvoiceTotalsFromItems($generalInvoice);
        $splitTrace('refreshInvoiceTotalsFromItems.general.2.finished');

        $depositInvoice = PembayaranInvoice::query()
            ->whereKey($depositInvoice->id)
            ->lockForUpdate()
            ->firstOrFail();

        $generalInvoice = PembayaranInvoice::query()
            ->whereKey($generalInvoice->id)
            ->lockForUpdate()
            ->firstOrFail();

        $depositInvoice->load(['items', 'metode', 'promos', 'depositClaims']);
        $generalInvoice->load(['items', 'metode', 'promos', 'depositClaims']);
        $splitTrace('reload.afterDepositRecords.finished', [
            'deposit_grand_total' => (float) ($depositInvoice->grand_total ?? 0),
            'general_grand_total' => (float) ($generalInvoice->grand_total ?? 0),
        ]);

        $memberBeforeLedger = $this->snapshotPaymentMemberReward($generalInvoice);
        $splitTrace('snapshotPaymentMemberReward.finished');

        $this->memberPointService->applyMemberBenefitToInvoice($generalInvoice, $this->username());
        $splitTrace('applyMemberBenefitToInvoice.general.finished');

        $this->memberPointService->applyMemberBenefitToInvoice($depositInvoice, $this->username());
        $splitTrace('applyMemberBenefitToInvoice.deposit.finished');

        $this->refreshInvoiceTotalsFromItems($generalInvoice);
        $splitTrace('refreshInvoiceTotalsFromItems.general.3.finished');

        $this->refreshInvoiceTotalsFromItems($depositInvoice);
        $splitTrace('refreshInvoiceTotalsFromItems.deposit.3.finished');

        $generalInvoice = PembayaranInvoice::query()
            ->whereKey($generalInvoice->id)
            ->lockForUpdate()
            ->firstOrFail();

        $depositInvoice = PembayaranInvoice::query()
            ->whereKey($depositInvoice->id)
            ->lockForUpdate()
            ->firstOrFail();

        $generalInvoice->load(['items', 'metode', 'promos', 'depositClaims']);
        $depositInvoice->load(['items', 'metode', 'promos', 'depositClaims']);
        $splitTrace('reload.afterMemberBenefit.finished', [
            'deposit_grand_total' => (float) ($depositInvoice->grand_total ?? 0),
            'general_grand_total' => (float) ($generalInvoice->grand_total ?? 0),
        ]);

        $this->nurseTreatmentMaterialService->syncFromInvoice($generalInvoice, $registrasi, $this->username());
        $splitTrace('nurseTreatmentMaterialService.syncFromInvoice.general.finished');

        $splitTrace('nurseTreatmentMaterialService.syncFromInvoice.deposit.skipped', [
            'reason' => 'Invoice deposit adalah pembelian saldo treatment, bukan tindakan perawat hari ini.',
        ]);

        $generalGrandTotal = (float) ($generalInvoice->grand_total ?? 0);
        $depositGrandTotal = (float) ($depositInvoice->grand_total ?? 0);
        $totalGrandTotal = $generalGrandTotal + $depositGrandTotal;

        $metode = $this->normalizeMetodePayloadForSplit($request, $totalGrandTotal);
        $totalBayar = collect($metode)->sum('nominal_dialokasikan');
        $splitTrace('normalizeMetodePayloadForSplit.finished', [
            'metode_count' => count($metode),
            'general_grand_total' => $generalGrandTotal,
            'deposit_grand_total' => $depositGrandTotal,
            'total_grand_total' => $totalGrandTotal,
            'total_bayar' => $totalBayar,
        ]);

        $this->validatePaymentAmount($totalGrandTotal, $totalBayar);
        $splitTrace('validatePaymentAmount.finished');

        $splitMetode = $this->splitMetodeForDepositInvoices(
            $metode,
            $generalGrandTotal,
            $depositGrandTotal
        );
        $splitTrace('splitMetodeForDepositInvoices.finished', [
            'general_metode_count' => count($splitMetode['general'] ?? []),
            'deposit_metode_count' => count($splitMetode['deposit'] ?? []),
        ]);

        $this->replaceInvoiceMetode($generalInvoice, $splitMetode['general'], 0);
        $splitTrace('replaceInvoiceMetode.general.finished');

        $this->replaceInvoiceMetode($depositInvoice, $splitMetode['deposit'], 4);
        $splitTrace('replaceInvoiceMetode.deposit.finished');

        $this->processStockKeluarPembayaran($generalInvoice, $registrasi);
        $splitTrace('processStockKeluarPembayaran.general.finished');

        $splitTrace('processStockKeluarPembayaran.deposit.skipped', [
            'reason' => 'Semua item produk/obat dipindah ke invoice umum saat split deposit.',
        ]);

        $this->createAccurateSyncPendingLog($generalInvoice, $registrasi);
        $splitTrace('createAccurateSyncPendingLog.general.finished');

        $this->createAccurateSyncPendingLog($depositInvoice, $registrasi);
        $splitTrace('createAccurateSyncPendingLog.deposit.finished');

        $this->markSplitInvoiceAsPaid($generalInvoice, $request, 0, null, $generalGrandTotal, 0);
        $splitTrace('markSplitInvoiceAsPaid.general.finished');

        $this->markSplitInvoiceAsPaid($depositInvoice, $request, 4, $depositExpiredAt, $depositGrandTotal, 0);
        $splitTrace('markSplitInvoiceAsPaid.deposit.finished');

        $generalInvoice->refresh();
        $depositInvoice->refresh();
        $splitTrace('invoice.refresh.afterPaid.finished');

        $this->memberPointService->processEarnedPointLedger($generalInvoice, $this->username());
        $splitTrace('processEarnedPointLedger.general.finished');

        $this->memberPointService->processEarnedPointLedger($depositInvoice, $this->username());
        $splitTrace('processEarnedPointLedger.deposit.finished');

        $memberReward = $this->buildPaymentMemberRewardResponse(
            $depositInvoice,
            $memberBeforeLedger,
            [$generalInvoice->id, $depositInvoice->id]
        );
        $splitTrace('buildPaymentMemberRewardResponse.finished');

        $nextTask = $this->completePaymentTask($registrasi);
        $splitTrace('completePaymentTask.finished', [
            'next_task_id' => $nextTask?->id,
        ]);

        $result = [
            'invoice_id' => $depositInvoice->id,
            'general_invoice_id' => $generalInvoice->id,
            'deposit_invoice_id' => $depositInvoice->id,
            'split_invoice_ids' => [
                'umum' => $generalInvoice->id,
                'deposit' => $depositInvoice->id,
            ],
            'next_task_id' => $nextTask?->id,
            'already_paid' => false,
            'member_reward' => $memberReward,
        ];

        $splitTrace('finished', [
            'general_invoice_id' => $generalInvoice->id,
            'deposit_invoice_id' => $depositInvoice->id,
        ]);

        return $result;
    }

    protected function activeInvoiceItemsForSplit(PembayaranInvoice $invoice)
    {
        return $invoice->items()
            ->where(function ($q) {
                $q->whereNull('is_delete')->orWhere('is_delete', 0);
            })
            ->where(function ($q) {
                $q->whereNull('status')->orWhere('status', '!=', 9);
            })
            ->lockForUpdate()
            ->get();
    }

    protected function resolveOrCreateGeneralInvoiceForDepositSplit(
        PembayaranInvoice $depositInvoice,
        RegistrasiKunjungan $registrasi
    ): PembayaranInvoice {
        $baseNo = trim((string) ($depositInvoice->invoice_base_no ?? ''));

        if ($baseNo === '') {
            $baseNo = $this->normalizeInvoiceNumberWithoutTransactionSuffix($depositInvoice->no_invoice ?? '');
        }

        if ($baseNo === '') {
            $baseNo = $this->normalizeInvoiceNumberWithoutTransactionSuffix(
                $this->generateInvoiceNumber($registrasi, 4, (int) $depositInvoice->id)
            );
        }

        $depositNo = $baseNo . '-D';
        $generalNo = $baseNo . '-U';

        $depositInvoice->forceFill($this->onlyExistingColumns('pembayaran_invoice', [
            'no_invoice' => $depositNo,
            'invoice_base_no' => $baseNo,
            'invoice_suffix' => 'D',
            'jenis_transaksi' => 4,
            'updated_by' => $this->username(),
            'updated_at' => now(),
        ]))->save();

        $existingGeneral = PembayaranInvoice::query()
            ->where('registrasi_id', $depositInvoice->registrasi_id)
            ->where('id', '<>', $depositInvoice->id)
            ->where(function ($q) {
                $q->whereNull('is_delete')->orWhere('is_delete', 0);
            })
            ->where(function ($q) use ($generalNo) {
                $q->where('no_invoice', $generalNo)
                    ->orWhere('invoice_suffix', 'U');
            })
            ->lockForUpdate()
            ->first();

        if ($existingGeneral) {
            if ((int) $existingGeneral->status === PembayaranInvoice::STATUS_LUNAS) {
                throw ValidationException::withMessages([
                    'invoice' => 'Invoice umum hasil split sudah lunas, transaksi deposit campuran tidak bisa diproses ulang.',
                ]);
            }

            $existingGeneral->forceFill($this->onlyExistingColumns('pembayaran_invoice', [
                'no_invoice' => $generalNo,
                'invoice_base_no' => $baseNo,
                'invoice_suffix' => 'U',
                'jenis_transaksi' => 0,
                'referensi_dokter_id' => null,
                'deposit_expired_option_id' => null,
                'deposit_expired_at' => null,
                'updated_by' => $this->username(),
                'updated_at' => now(),
            ]))->save();

            return $existingGeneral->fresh();
        }

        if ($this->invoiceNumberExists($generalNo, null)) {
            throw ValidationException::withMessages([
                'no_invoice' => 'Nomor invoice umum hasil split sudah digunakan: ' . $generalNo,
            ]);
        }

        $generalInvoice = $depositInvoice->replicate();

        $generalInvoice->forceFill($this->onlyExistingColumns('pembayaran_invoice', [
            'no_invoice' => $generalNo,
            'invoice_base_no' => $baseNo,
            'invoice_suffix' => 'U',
            'jenis_transaksi' => 0,
            'referensi_dokter_id' => null,
            'deposit_expired_option_id' => null,
            'deposit_expired_at' => null,
            'tanggal_lunas' => null,
            'subtotal_obat' => 0,
            'subtotal_produk' => 0,
            'subtotal_treatment' => 0,
            'subtotal_konsultasi' => 0,
            'subtotal' => 0,
            'diskon_subtotal' => 0,
            'diskon_subtotal_tipe' => 0,
            'diskon_subtotal_nilai' => 0,
            'diskon_subtotal_amount' => 0,
            'diskon_promo' => 0,
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
            'status' => PembayaranInvoice::STATUS_PROSES,
            'is_delete' => 0,
            'created_by' => $this->username(),
            'updated_by' => $this->username(),
            'created_at' => now(),
            'updated_at' => now(),
        ]));

        $generalInvoice->save();

        return $generalInvoice->fresh();
    }

    protected function splitDepositInvoiceItems(
        PembayaranInvoice $depositInvoice,
        PembayaranInvoice $generalInvoice,
        array $selectedDepositItems
    ): void {
        $items = $this->activeInvoiceItemsForSplit($depositInvoice);

        foreach ($items as $item) {
            $itemId = (int) ($item->id ?? 0);

            if (!$this->isTreatmentItem($item)) {
                $this->moveInvoiceItemToGeneralInvoice($item, $generalInvoice);
                continue;
            }

            if (!array_key_exists($itemId, $selectedDepositItems)) {
                $this->moveInvoiceItemToGeneralInvoice($item, $generalInvoice);
                continue;
            }

            $invoiceQty = (float) ($item->qty ?? $item->jumlah ?? 0);
            if ($invoiceQty <= 0) {
                $invoiceQty = 1;
            }

            $depositQty = (float) ($selectedDepositItems[$itemId] ?? 0);
            if ($depositQty <= 0) {
                $depositQty = $invoiceQty;
            }

            if ($depositQty > $invoiceQty) {
                throw ValidationException::withMessages([
                    'deposit_treatment_items' => 'Qty deposit untuk ' . ($item->nama_item ?? 'treatment') . ' tidak boleh melebihi qty invoice.',
                ]);
            }

            if ($depositQty < $invoiceQty) {
                $this->splitTreatmentItemQtyToGeneralInvoice(
                    $item,
                    $generalInvoice,
                    $depositQty,
                    $invoiceQty
                );
            } else {
                $item->forceFill($this->onlyExistingColumns('pembayaran_invoice_item', [
                    'jenis_transaksi' => 4,
                    'updated_by' => $this->username(),
                    'updated_at' => now(),
                ]))->save();
            }
        }

        $depositInvoice->load('items');
        $generalInvoice->load('items');
    }

    protected function moveInvoiceItemToGeneralInvoice($item, PembayaranInvoice $generalInvoice): void
    {
        $item->forceFill($this->onlyExistingColumns('pembayaran_invoice_item', [
            'pembayaran_id' => $generalInvoice->id,
            'pembayaran_invoice_id' => $generalInvoice->id,
            'invoice_id' => $generalInvoice->id,
            'jenis_transaksi' => 0,
            'deposit_treatment_id' => null,
            'deposit_claim_id' => null,
            'expired_at' => null,
            'updated_by' => $this->username(),
            'updated_at' => now(),
        ]))->save();
    }

    protected function splitTreatmentItemQtyToGeneralInvoice(
        $item,
        PembayaranInvoice $generalInvoice,
        float $depositQty,
        float $invoiceQty
    ): void {
        $remainingQty = round($invoiceQty - $depositQty, 4);

        if ($remainingQty <= 0) {
            return;
        }

        $generalItem = $item->replicate();

        $generalPayload = array_merge(
            $this->prorateInvoiceItemPayload($item, $remainingQty, $invoiceQty),
            [
                'pembayaran_id' => $generalInvoice->id,
                'pembayaran_invoice_id' => $generalInvoice->id,
                'invoice_id' => $generalInvoice->id,
                'jenis_transaksi' => 0,
                'deposit_treatment_id' => null,
                'deposit_claim_id' => null,
                'expired_at' => null,
                'created_by' => $this->username(),
                'updated_by' => $this->username(),
                'created_at' => now(),
                'updated_at' => now(),
            ]
        );

        $generalItem->forceFill(
            $this->onlyExistingColumns('pembayaran_invoice_item', $generalPayload)
        )->save();

        $depositPayload = array_merge(
            $this->prorateInvoiceItemPayload($item, $depositQty, $invoiceQty),
            [
                'jenis_transaksi' => 4,
                'updated_by' => $this->username(),
                'updated_at' => now(),
            ]
        );

        $item->forceFill(
            $this->onlyExistingColumns('pembayaran_invoice_item', $depositPayload)
        )->save();
    }

    protected function prorateInvoiceItemPayload($item, float $targetQty, float $originalQty): array
    {
        if ($originalQty <= 0) {
            $originalQty = 1;
        }

        $ratio = $targetQty / $originalQty;

        $payload = [
            'qty' => round($targetQty, 4),
            'jumlah' => round($targetQty, 4),
        ];

        foreach ([
            'gross_subtotal',
            'total_harga_awal',
            'diskon_amount',
            'diskon_referral',
            'subtotal_before_diskon_subtotal',
            'diskon_subtotal_amount',
            'subtotal_after_diskon_subtotal',
            'subtotal',
            'total',
            'total_harga',
            'hpp_total',
        ] as $column) {
            if (Schema::hasColumn('pembayaran_invoice_item', $column)) {
                $payload[$column] = round(((float) ($item->{$column} ?? 0)) * $ratio, 2);
            }
        }

        return $payload;
    }

    protected function hasPromoPayloadForSplitDeposit(Request $request): bool
    {
        if ((float) $request->input('diskon_subtotal_nilai', 0) > 0) {
            return true;
        }

        if ($request->filled('promo_code')) {
            return true;
        }

        foreach (['promo_ids', 'promos', 'selected_promos', 'applied_promos'] as $key) {
            $rows = $request->input($key, []);

            if (is_array($rows) && count($rows) > 0) {
                return true;
            }
        }

        return false;
    }

    protected function resetSplitInvoiceDiscountsAndPromosLite(PembayaranInvoice $invoice): void
    {
        $this->resetSplitInvoicePromosAndAmount($invoice);

        if (Schema::hasTable('pembayaran_invoice_item')) {
            $itemPayload = [
                'diskon_subtotal_amount' => 0,
                'subtotal_before_diskon_subtotal' => DB::raw('COALESCE(subtotal, 0)'),
                'subtotal_after_diskon_subtotal' => DB::raw('COALESCE(subtotal, 0)'),
                'updated_by' => $this->username(),
                'updated_at' => now(),
            ];

            $itemPayload = $this->onlyExistingColumns('pembayaran_invoice_item', $itemPayload);

            if (!empty($itemPayload)) {
                DB::table('pembayaran_invoice_item')
                    ->where('pembayaran_id', $invoice->id)
                    ->where(function ($q) {
                        $q->whereNull('is_delete')->orWhere('is_delete', 0);
                    })
                    ->where(function ($q) {
                        $q->whereNull('status')->orWhere('status', '!=', 9);
                    })
                    ->update($itemPayload);
            }
        }

        $invoice->update($this->onlyExistingColumns('pembayaran_invoice', [
            'diskon_subtotal' => 0,
            'diskon_subtotal_tipe' => 0,
            'diskon_subtotal_nilai' => 0,
            'diskon_subtotal_amount' => 0,
            'diskon_promo' => 0,
            'total_promo' => 0,
            'updated_by' => $this->username(),
            'updated_at' => now(),
        ]));
    }



    protected function applyDiscountsAndPromosForSplitDepositInvoices(
        Request $request,
        PembayaranInvoice $generalInvoice,
        PembayaranInvoice $depositInvoice
    ): void {
        $this->applySubtotalDiscountForSplitInvoices($request, $generalInvoice, $depositInvoice);

        $this->refreshInvoiceTotalsFromItems($generalInvoice);
        $this->refreshInvoiceTotalsFromItems($depositInvoice);

        $generalInvoice = PembayaranInvoice::query()
            ->whereKey($generalInvoice->id)
            ->lockForUpdate()
            ->firstOrFail();

        $depositInvoice = PembayaranInvoice::query()
            ->whereKey($depositInvoice->id)
            ->lockForUpdate()
            ->firstOrFail();

        $this->applySelectedPromosForSplitInvoices($request, $generalInvoice, $depositInvoice);

        $this->refreshInvoiceTotalsFromItems($generalInvoice);
        $this->refreshInvoiceTotalsFromItems($depositInvoice);

        $generalInvoice = PembayaranInvoice::query()
            ->whereKey($generalInvoice->id)
            ->lockForUpdate()
            ->firstOrFail();

        $depositInvoice = PembayaranInvoice::query()
            ->whereKey($depositInvoice->id)
            ->lockForUpdate()
            ->firstOrFail();

        $this->updateSplitPromoAttribution($generalInvoice, $depositInvoice);
    }

    protected function applySubtotalDiscountForSplitInvoices(
        Request $request,
        PembayaranInvoice $generalInvoice,
        PembayaranInvoice $depositInvoice
    ): void {
        $discountValue = (float) $request->input('diskon_subtotal_nilai', 0);
        $discountType = $this->normalizeSubtotalDiscountType($request->input('diskon_subtotal_tipe'));

        $generalBase = $this->getSplitInvoiceSubtotalDiscountBase($generalInvoice);
        $depositBase = $this->getSplitInvoiceSubtotalDiscountBase($depositInvoice);
        $totalBase = $generalBase + $depositBase;

        if ($discountValue <= 0 || $discountType === 0 || $totalBase <= 0) {
            $this->applySubtotalDiscountFromRequest($this->buildSplitSubtotalDiscountRequest($request, 0), $generalInvoice);
            $this->applySubtotalDiscountFromRequest($this->buildSplitSubtotalDiscountRequest($request, 0), $depositInvoice);
            return;
        }

        $discountAmount = $discountType === 1
            ? round(($totalBase * $discountValue) / 100, 2)
            : round($discountValue, 2);

        $discountAmount = min(max($discountAmount, 0), $totalBase);

        $generalAmount = $totalBase > 0 ? round(($generalBase / $totalBase) * $discountAmount, 2) : 0;
        $depositAmount = round($discountAmount - $generalAmount, 2);

        $generalAmount = min(max($generalAmount, 0), $generalBase);
        $depositAmount = min(max($depositAmount, 0), $depositBase);

        $this->applySubtotalDiscountFromRequest($this->buildSplitSubtotalDiscountRequest($request, $generalAmount), $generalInvoice);
        $this->applySubtotalDiscountFromRequest($this->buildSplitSubtotalDiscountRequest($request, $depositAmount), $depositInvoice);
    }

    protected function buildSplitSubtotalDiscountRequest(Request $request, float $amount): Request
    {
        $payload = $request->all();
        $payload['diskon_subtotal_tipe'] = 2;
        $payload['diskon_subtotal_nilai'] = round(max($amount, 0), 2);

        return Request::create('/', 'POST', $payload);
    }

    protected function getSplitInvoiceSubtotalDiscountBase(PembayaranInvoice $invoice): float
    {
        if (!Schema::hasTable('pembayaran_invoice_item')) {
            return 0;
        }

        return (float) DB::table('pembayaran_invoice_item')
            ->where('pembayaran_id', $invoice->id)
            ->whereIn('item_type', [2, 3])
            ->where(function ($q) {
                $q->whereNull('is_delete')->orWhere('is_delete', 0);
            })
            ->where(function ($q) {
                $q->whereNull('status')->orWhere('status', '!=', 9);
            })
            ->sum('subtotal');
    }

    protected function applySelectedPromosForSplitInvoices(
        Request $request,
        PembayaranInvoice $generalInvoice,
        PembayaranInvoice $depositInvoice
    ): void {
        $this->resetSplitInvoicePromosAndAmount($generalInvoice);
        $this->resetSplitInvoicePromosAndAmount($depositInvoice);

        $references = $this->normalizeSelectedPromoReferences($request);
        if (count($references) === 0) {
            return;
        }

        $selectedVouchers = [];
        foreach ($references as $reference) {
            $voucher = $this->findVoucherByReference($reference);
            if (!$voucher) {
                throw ValidationException::withMessages([
                    'promo' => 'Voucher tidak ditemukan atau tidak aktif.',
                ]);
            }

            $voucherId = (int) $voucher->id;
            if (isset($selectedVouchers[$voucherId])) {
                $existingReference = $selectedVouchers[$voucherId]['reference'] ?? [];
                $existingCode = trim((string) ($existingReference['kode'] ?? $existingReference['kode_voucher'] ?? ''));
                $newCode = trim((string) ($reference['kode'] ?? $reference['kode_voucher'] ?? ''));

                if ($existingCode === '' && $newCode !== '') {
                    $selectedVouchers[$voucherId]['reference'] = $reference;
                }

                continue;
            }

            $selectedVouchers[$voucherId] = [
                'voucher' => $voucher,
                'reference' => $reference,
            ];
        }

        $nonCombine = collect($selectedVouchers)
            ->filter(fn ($row) => (int) ($row['voucher']->is_bisa_digabung_promo ?? 0) !== 1);

        if ($nonCombine->count() > 0 && count($selectedVouchers) > 1) {
            throw ValidationException::withMessages([
                'promo' => 'Voucher tidak bisa digabung dengan promo lain.',
            ]);
        }

        $combinedPromoBase = $this->getSplitCombinedPromoBase($generalInvoice, $depositInvoice);
        $totalPromo = 0.0;
        $layerOrder = 1;

        foreach ($selectedVouchers as $selected) {
            $voucher = $selected['voucher'];
            $reference = $selected['reference'];

            $this->validateVoucherForInvoice($voucher, $generalInvoice);

            $items = $this->loadSplitVoucherInvoiceItems($generalInvoice, $depositInvoice);
            $eligibleItems = $this->resolveSplitVoucherEligibleItems($voucher, $items);

            if ($eligibleItems->isEmpty()) {
                throw ValidationException::withMessages([
                    'promo' => 'Voucher ' . ($voucher->nama_voucher ?? $voucher->kode_voucher ?? '-') . ' tidak sesuai dengan item transaksi.',
                ]);
            }

            $eligibleBase = (float) $eligibleItems->sum(fn ($item) => $this->splitItemNetBase($item));
            if ($eligibleBase <= 0) {
                throw ValidationException::withMessages([
                    'promo' => 'Voucher ' . ($voucher->nama_voucher ?? $voucher->kode_voucher ?? '-') . ' tidak memiliki nilai eligible.',
                ]);
            }

            $voucherAmount = $this->calculateSplitVoucherDiscountAmount($voucher, $eligibleBase);
            $remainingBase = max($combinedPromoBase - $totalPromo, 0);
            $voucherAmount = min($voucherAmount, $remainingBase);

            if ($voucherAmount <= 0) {
                continue;
            }

            if ($this->isSplitValueVoucher($voucher)) {
                $allocations = $this->distributeSplitVoucherAmountAcrossInvoices($eligibleItems, $voucherAmount);
                foreach ($allocations as $allocation) {
                    $this->insertSplitInvoicePromoRow(
                        (int) $allocation['pembayaran_id'],
                        null,
                        $voucher,
                        (float) $allocation['amount'],
                        1,
                        $layerOrder,
                        'VALUE',
                        (float) $allocation['base'],
                        $eligibleBase,
                        max((float) $allocation['base'] - (float) $allocation['amount'], 0)
                    );
                }
            } else {
                $allocations = $this->distributeSplitVoucherAmountAcrossItems($eligibleItems, $voucherAmount);
                foreach ($allocations as $allocation) {
                    $this->insertSplitInvoicePromoRow(
                        (int) $allocation['pembayaran_id'],
                        (int) $allocation['pembayaran_item_id'],
                        $voucher,
                        (float) $allocation['amount'],
                        2,
                        $layerOrder,
                        $this->resolveSplitPromoScopeCode($voucher),
                        (float) $allocation['base'],
                        $eligibleBase,
                        max((float) $allocation['base'] - (float) $allocation['amount'], 0)
                    );
                }
            }

            $this->consumeVoucherQuotaIfNeeded($voucher, $generalInvoice);
            $this->redeemSplitVoucherCodeIfNeeded($voucher, $generalInvoice, $depositInvoice, $reference);

            $totalPromo += $voucherAmount;
            $layerOrder++;
        }

        $this->updateSplitInvoicePromoAmount($generalInvoice);
        $this->updateSplitInvoicePromoAmount($depositInvoice);
    }

    protected function resetSplitInvoicePromosAndAmount(PembayaranInvoice $invoice): void
    {
        if (Schema::hasTable('pembayaran_invoice_promo')) {
            DB::table('pembayaran_invoice_promo')
                ->where('pembayaran_id', $invoice->id)
                ->where(function ($q) {
                    $q->whereNull('is_delete')->orWhere('is_delete', 0);
                })
                ->update($this->onlyExistingColumns('pembayaran_invoice_promo', [
                    'is_delete' => 1,
                    'updated_by' => $this->username(),
                    'updated_at' => now(),
                ]));
        }

        $invoice->update($this->onlyExistingColumns('pembayaran_invoice', [
            'total_promo' => 0,
            'diskon_promo' => 0,
            'updated_by' => $this->username(),
            'updated_at' => now(),
        ]));
    }

    protected function getSplitCombinedPromoBase(PembayaranInvoice $generalInvoice, PembayaranInvoice $depositInvoice): float
    {
        return (float) $this->loadSplitVoucherInvoiceItems($generalInvoice, $depositInvoice)
            ->sum(fn ($item) => $this->splitItemNetBase($item));
    }

    protected function loadSplitVoucherInvoiceItems(PembayaranInvoice $generalInvoice, PembayaranInvoice $depositInvoice)
    {
        return $this->loadSingleInvoiceItemsForSplitVoucher($generalInvoice)
            ->merge($this->loadSingleInvoiceItemsForSplitVoucher($depositInvoice))
            ->values();
    }

    protected function loadSingleInvoiceItemsForSplitVoucher(PembayaranInvoice $invoice)
    {
        return $invoice->items()
            ->where(function ($q) {
                $q->whereNull('is_delete')->orWhere('is_delete', 0);
            })
            ->where(function ($q) {
                $q->whereNull('status')->orWhere('status', '!=', 9);
            })
            ->whereIn('item_type', [2, 3])
            ->lockForUpdate()
            ->get()
            ->map(function ($item) use ($invoice) {
                $item->setAttribute('split_pembayaran_id', (int) $invoice->id);
                return $item;
            });
    }

    protected function resolveSplitVoucherEligibleItems(object $voucher, $items)
    {
        $items = collect($items)->filter(fn ($item) => $this->isProductItem($item) || $this->isTreatmentItem($item))->values();

        $specificItems = collect();
        if (Schema::hasTable('master_voucher_diskon_item')) {
            $specificItems = DB::table('master_voucher_diskon_item')
                ->where('voucher_diskon_id', $voucher->id)
                ->where(function ($q) {
                    $q->whereNull('is_delete')->orWhere('is_delete', 0);
                })
                ->get();
        }

        if ($specificItems->isNotEmpty()) {
            return $items->filter(function ($item) use ($specificItems) {
                return $specificItems->contains(function ($rule) use ($item) {
                    $ruleType = strtolower((string) ($rule->item_type ?? ''));
                    $ruleItemId = (int) ($rule->item_id ?? 0);

                    if ($ruleType === 'produk') {
                        return $this->isProductItem($item)
                            && in_array($ruleItemId, [
                                (int) ($item->produk_id ?? 0),
                                (int) ($item->item_id ?? 0),
                                (int) ($item->master_produk_id ?? 0),
                            ], true);
                    }

                    if ($ruleType === 'treatment') {
                        return $this->isTreatmentItem($item)
                            && in_array($ruleItemId, [
                                (int) ($item->treatment_id ?? 0),
                                (int) ($item->item_id ?? 0),
                                (int) ($item->master_treatment_id ?? 0),
                            ], true);
                    }

                    return false;
                });
            })->values();
        }

        $jenisVoucherId = (int) ($voucher->jenis_voucher_id ?? 0);

        if ($jenisVoucherId === 1) {
            return $items->filter(fn ($item) => $this->isTreatmentItem($item))->values();
        }

        if ($jenisVoucherId === 2) {
            return $items->filter(fn ($item) => $this->isProductItem($item))->values();
        }

        if ($jenisVoucherId === 3) {
            $hasTreatment = $items->contains(fn ($item) => $this->isTreatmentItem($item));
            $hasProduct = $items->contains(fn ($item) => $this->isProductItem($item));

            return $hasTreatment && $hasProduct ? $items->values() : collect();
        }

        return $items->values();
    }

    protected function calculateSplitVoucherDiscountAmount(object $voucher, float $eligibleBase): float
    {
        if ($eligibleBase <= 0) {
            return 0;
        }

        $tipe = strtolower((string) ($voucher->tipe_diskon ?? 'nominal'));
        $nilai = (float) ($voucher->total_diskon ?? 0);
        $max = (float) ($voucher->total_diskon_maksimal ?? 0);

        if (in_array($tipe, ['percent', 'persen', '%', '1'], true)) {
            $amount = round(($eligibleBase * $nilai) / 100, 2);
            if ($max > 0) {
                $amount = min($amount, $max);
            }
            return min(max($amount, 0), $eligibleBase);
        }

        return min(max(round($nilai, 2), 0), $eligibleBase);
    }

    protected function distributeSplitVoucherAmountAcrossItems($items, float $amount): array
    {
        $items = collect($items)->values();
        $base = (float) $items->sum(fn ($item) => $this->splitItemNetBase($item));
        $amount = min(max(round($amount, 2), 0), max($base, 0));

        if ($items->isEmpty() || $base <= 0 || $amount <= 0) {
            return [];
        }

        $allocations = [];
        $allocated = 0.0;
        $lastIndex = $items->count() - 1;

        foreach ($items as $index => $item) {
            $itemBase = $this->splitItemNetBase($item);
            $itemAmount = $index === $lastIndex
                ? round($amount - $allocated, 2)
                : round(($itemBase / $base) * $amount, 2);

            $itemAmount = min(max($itemAmount, 0), $itemBase);
            $allocated += $itemAmount;

            if ($itemAmount <= 0) {
                continue;
            }

            $allocations[] = [
                'pembayaran_id' => (int) ($item->split_pembayaran_id ?? $item->pembayaran_id ?? 0),
                'pembayaran_item_id' => (int) ($item->id ?? 0),
                'amount' => $itemAmount,
                'base' => $itemBase,
            ];
        }

        return $allocations;
    }

    protected function distributeSplitVoucherAmountAcrossInvoices($items, float $amount): array
    {
        $groups = collect($items)
            ->groupBy(fn ($item) => (int) ($item->split_pembayaran_id ?? $item->pembayaran_id ?? 0))
            ->map(function ($rows, $invoiceId) {
                return [
                    'pembayaran_id' => (int) $invoiceId,
                    'base' => (float) collect($rows)->sum(fn ($item) => $this->splitItemNetBase($item)),
                ];
            })
            ->filter(fn ($row) => (float) $row['base'] > 0)
            ->values();

        $base = (float) $groups->sum('base');
        $amount = min(max(round($amount, 2), 0), max($base, 0));

        if ($groups->isEmpty() || $base <= 0 || $amount <= 0) {
            return [];
        }

        $allocations = [];
        $allocated = 0.0;
        $lastIndex = $groups->count() - 1;

        foreach ($groups as $index => $group) {
            $groupAmount = $index === $lastIndex
                ? round($amount - $allocated, 2)
                : round(((float) $group['base'] / $base) * $amount, 2);

            $groupAmount = min(max($groupAmount, 0), (float) $group['base']);
            $allocated += $groupAmount;

            if ($groupAmount <= 0) {
                continue;
            }

            $allocations[] = [
                'pembayaran_id' => (int) $group['pembayaran_id'],
                'amount' => $groupAmount,
                'base' => (float) $group['base'],
            ];
        }

        return $allocations;
    }

    protected function insertSplitInvoicePromoRow(
        int $invoiceId,
        ?int $itemId,
        object $voucher,
        float $amount,
        int $scopeType,
        int $layerOrder,
        string $scopeCode,
        float $eligibleBaseAmount,
        float $baseAfterPreviousPromo,
        float $netAfterPromo
    ): void {
        if (!Schema::hasTable('pembayaran_invoice_promo') || $amount <= 0) {
            return;
        }

        DB::table('pembayaran_invoice_promo')->insert($this->onlyExistingColumns('pembayaran_invoice_promo', [
            'pembayaran_id' => $invoiceId,
            'pembayaran_item_id' => $itemId,
            'voucher_id' => $voucher->id,
            'kode_voucher' => $voucher->kode_voucher ?? null,
            'nama_voucher' => $voucher->nama_voucher ?? null,
            'scope_type' => $scopeType,
            'promo_scope_code' => $scopeCode,
            'promo_layer_order' => $layerOrder,
            'diskon_tipe' => $this->normalizePromoDiscountType($voucher->tipe_diskon ?? $voucher->diskon_tipe ?? 2),
            'diskon_nilai' => $voucher->total_diskon ?? 0,
            'diskon_amount' => round($amount, 2),
            'eligible_base_amount' => round($eligibleBaseAmount, 2),
            'base_after_previous_promo' => round($baseAfterPreviousPromo, 2),
            'net_after_promo' => round($netAfterPromo, 2),
            'final_net_attribution' => 0,
            'attribution_method' => 'discount_weighted',
            'catatan' => 'Validated by backend split finalizer',
            'is_delete' => 0,
            'created_by' => $this->username(),
            'updated_by' => $this->username(),
            'created_at' => now(),
            'updated_at' => now(),
        ]));
    }

    protected function updateSplitInvoicePromoAmount(PembayaranInvoice $invoice): void
    {
        if (!Schema::hasTable('pembayaran_invoice_promo')) {
            return;
        }

        $totalPromo = (float) DB::table('pembayaran_invoice_promo')
            ->where('pembayaran_id', $invoice->id)
            ->where(function ($q) {
                $q->whereNull('is_delete')->orWhere('is_delete', 0);
            })
            ->sum('diskon_amount');

        $invoice->update($this->onlyExistingColumns('pembayaran_invoice', [
            'total_promo' => round($totalPromo, 2),
            'diskon_promo' => round($totalPromo, 2),
            'updated_by' => $this->username(),
            'updated_at' => now(),
        ]));
    }

    protected function updateSplitPromoAttribution(PembayaranInvoice $generalInvoice, PembayaranInvoice $depositInvoice): void
    {
        if (!Schema::hasTable('pembayaran_invoice_promo') || !Schema::hasColumn('pembayaran_invoice_promo', 'final_net_attribution')) {
            return;
        }

        $invoiceIds = [(int) $generalInvoice->id, (int) $depositInvoice->id];
        $promoRows = DB::table('pembayaran_invoice_promo')
            ->whereIn('pembayaran_id', $invoiceIds)
            ->where(function ($q) {
                $q->whereNull('is_delete')->orWhere('is_delete', 0);
            })
            ->lockForUpdate()
            ->get();

        if ($promoRows->isEmpty()) {
            return;
        }

        $totalDiscount = (float) $promoRows->sum(fn ($row) => (float) ($row->diskon_amount ?? 0));
        if ($totalDiscount <= 0) {
            return;
        }

        $generalInvoice->refresh();
        $depositInvoice->refresh();

        $totalNetRevenue = (float) ($generalInvoice->grand_total ?? 0) + (float) ($depositInvoice->grand_total ?? 0);
        $allocated = 0.0;
        $lastIndex = $promoRows->count() - 1;

        foreach ($promoRows->values() as $index => $row) {
            $amount = (float) ($row->diskon_amount ?? 0);
            $attribution = $index === $lastIndex
                ? round($totalNetRevenue - $allocated, 2)
                : round(($amount / $totalDiscount) * $totalNetRevenue, 2);

            $attribution = max($attribution, 0);
            $allocated += $attribution;

            DB::table('pembayaran_invoice_promo')
                ->where('id', $row->id)
                ->update($this->onlyExistingColumns('pembayaran_invoice_promo', [
                    'final_net_attribution' => $attribution,
                    'attribution_method' => 'discount_weighted',
                    'updated_by' => $this->username(),
                    'updated_at' => now(),
                ]));
        }
    }

    protected function redeemSplitVoucherCodeIfNeeded(
        object $voucher,
        PembayaranInvoice $generalInvoice,
        PembayaranInvoice $depositInvoice,
        array $reference = []
    ): void {
        if (!Schema::hasTable('master_voucher_diskon_kode')) {
            return;
        }

        $kode = trim((string) ($reference['kode'] ?? $reference['kode_voucher'] ?? ''));
        $query = DB::table('master_voucher_diskon_kode')
            ->where('voucher_diskon_id', $voucher->id)
            ->where(function ($q) {
                $q->whereNull('is_delete')->orWhere('is_delete', 0);
            })
            ->where(function ($q) use ($generalInvoice, $depositInvoice) {
                $q->where('status_kode', 1)
                    ->orWhere(function ($sub) use ($generalInvoice, $depositInvoice) {
                        $sub->where('status_kode', 2)
                            ->whereIn('redeemed_invoice_no', array_filter([
                                $generalInvoice->no_invoice,
                                $depositInvoice->no_invoice,
                                $generalInvoice->invoice_base_no,
                                $depositInvoice->invoice_base_no,
                            ]));
                    });
            });

        if ($kode !== '') {
            $query->where('kode_voucher', $kode);
        }

        $row = $query->orderBy('id')->lockForUpdate()->first();
        if (!$row) {
            return;
        }

        $redeemedInvoiceNo = $generalInvoice->invoice_base_no
            ?: $depositInvoice->invoice_base_no
            ?: $generalInvoice->no_invoice;

        DB::table('master_voucher_diskon_kode')
            ->where('id', $row->id)
            ->update($this->onlyExistingColumns('master_voucher_diskon_kode', [
                'status_kode' => 2,
                'used_at' => now(),
                'redeemed_invoice_no' => $redeemedInvoiceNo,
                'redeemed_pasien_id' => $generalInvoice->pasien_id ?? $depositInvoice->pasien_id,
                'updated_by' => $this->username(),
                'updated_at' => now(),
            ]));
    }

    protected function splitItemNetBase($item): float
    {
        $subtotalAfter = (float) ($item->subtotal_after_diskon_subtotal ?? 0);
        if ($subtotalAfter > 0) {
            return $subtotalAfter;
        }

        $subtotalBefore = (float) ($item->subtotal_before_diskon_subtotal ?? 0);
        $diskonSubtotal = (float) ($item->diskon_subtotal_amount ?? 0);
        if ($subtotalBefore > 0) {
            return max($subtotalBefore - $diskonSubtotal, 0);
        }

        return max((float) ($item->subtotal ?? $item->total ?? 0), 0);
    }

    protected function isSplitValueVoucher(object $voucher): bool
    {
        return (int) ($voucher->jenis_voucher_id ?? 0) === 4;
    }

    protected function resolveSplitPromoScopeCode(object $voucher): string
    {
        return match ((int) ($voucher->jenis_voucher_id ?? 0)) {
            1 => 'TREATMENT',
            2 => 'PRODUK',
            3 => 'BUNDLING',
            4 => 'VALUE',
            default => 'ITEM',
        };
    }

    protected function normalizeMetodePayloadForSplit(Request $request, float $totalGrandTotal): array
    {
        $metode = $request->input('metode', []);

        if (is_array($metode) && count($metode) > 0) {
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

        $jumlahBayar = (float) $request->input('jumlah_bayar', $totalGrandTotal);
        $nama = $request->input('metode_pembayaran', 'CASH');

        return [[
            'metode_bayar_id' => null,
            'metode_bayar_nama' => $nama,
            'metode_bayar_tipe' => 1,
            'nominal_dialokasikan' => $jumlahBayar,
            'nominal_diterima' => $jumlahBayar,
            'nominal_kembalian' => 0,
            'no_referensi' => null,
            'catatan' => null,
        ]];
    }

    protected function splitMetodeForDepositInvoices(array $metode, float $generalAmount, float $depositAmount): array
    {
        $pool = collect($metode)
            ->map(function ($row) {
                $row['nominal_dialokasikan'] = round((float) ($row['nominal_dialokasikan'] ?? 0), 2);
                $row['nominal_diterima'] = round((float) ($row['nominal_diterima'] ?? $row['nominal_dialokasikan']), 2);
                $row['nominal_kembalian'] = round((float) ($row['nominal_kembalian'] ?? 0), 2);
                return $row;
            })
            ->values()
            ->all();

        $generalMetode = $this->consumeMetodeAllocation($pool, $generalAmount);
        $depositMetode = $this->consumeMetodeAllocation($pool, $depositAmount);

        return [
            'general' => $generalMetode,
            'deposit' => $depositMetode,
        ];
    }

    protected function consumeMetodeAllocation(array &$pool, float $targetAmount): array
    {
        $targetAmount = round(max($targetAmount, 0), 2);
        $remaining = $targetAmount;
        $result = [];

        foreach ($pool as $index => $row) {
            if ($remaining <= 0) {
                break;
            }

            $available = round((float) ($row['nominal_dialokasikan'] ?? 0), 2);

            if ($available <= 0) {
                continue;
            }

            $amount = min($available, $remaining);

            $allocated = $row;
            $allocated['nominal_dialokasikan'] = $amount;
            $allocated['nominal_diterima'] = $amount;
            $allocated['nominal_kembalian'] = 0;

            $result[] = $allocated;

            $pool[$index]['nominal_dialokasikan'] = round($available - $amount, 2);
            $pool[$index]['nominal_diterima'] = round(max(((float) ($row['nominal_diterima'] ?? 0)) - $amount, 0), 2);

            $remaining = round($remaining - $amount, 2);
        }

        if ($remaining > 0) {
            throw ValidationException::withMessages([
                'jumlah_bayar' => 'Alokasi metode pembayaran tidak cukup untuk invoice hasil split.',
            ]);
        }

        return $result;
    }

    protected function markSplitInvoiceAsPaid(
        PembayaranInvoice $invoice,
        Request $request,
        int $jenisTransaksi,
        ?string $depositExpiredAt,
        float $totalBayar,
        float $totalKembalian = 0
    ): void {
        $grandTotal = (float) ($invoice->grand_total ?? 0);

        $invoice->update($this->onlyExistingColumns('pembayaran_invoice', [
            'tanggal_lunas' => now(),
            'jenis_transaksi' => $jenisTransaksi,
            'is_premier' => $this->resolveIsPremierFromRequest($request, $invoice),
            'referensi_dokter_id' => $jenisTransaksi === 4
                ? $request->input('referensi_dokter_id')
                : null,
            'deposit_expired_option_id' => $jenisTransaksi === 4
                ? $request->input('deposit_expired_option_id')
                : null,
            'deposit_expired_at' => $jenisTransaksi === 4
                ? $depositExpiredAt
                : null,
            'sumber_informasi_id' => $request->input('sumber_informasi_id', $invoice->sumber_informasi_id ?? null),
            'sumber_kedatangan' => $request->input('sumber_kedatangan', $invoice->sumber_kedatangan ?? null),
            'catatan' => $request->input('catatan_pembayaran', $invoice->catatan ?? null),
            'grand_total' => $grandTotal,
            'total_bayar' => $totalBayar,
            'sisa_tagihan' => 0,
            'total_kembalian' => $totalKembalian,
            'status' => PembayaranInvoice::STATUS_LUNAS,
            'updated_by' => $this->username(),
            'updated_at' => now(),
        ]));
    }

    protected function completePaymentTask(RegistrasiKunjungan $registrasi): ?RegistrasiTask
    {
        $registrasi->loadMissing('tasks');
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
            ->lockForUpdate()
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

        return $nextTask;
    }

    protected function resolveDepositExpiredAt(Request $request): ?string
    {
        if ($request->filled('deposit_expired_at')) {
            return Carbon::parse($request->input('deposit_expired_at'))->toDateString();
        }

        if (!$request->filled('deposit_expired_option_id')) {
            return null;
        }

        if (!Schema::hasTable('master_deposit_expired_option')) {
            return null;
        }

        $option = DB::table('master_deposit_expired_option')
            ->where('id', $request->input('deposit_expired_option_id'))
            ->where(function ($q) {
                $q->whereNull('is_delete')->orWhere('is_delete', 0);
            })
            ->where(function ($q) {
                $q->whereNull('is_active')->orWhere('is_active', 1);
            })
            ->first();

        if (!$option) {
            throw ValidationException::withMessages([
                'deposit_expired_option_id' => 'Masa berlaku deposit tidak valid atau tidak aktif.',
            ]);
        }

        $jumlahHari = (int) ($option->jumlah_hari ?? 0);

        if ($jumlahHari <= 0) {
            throw ValidationException::withMessages([
                'deposit_expired_at' => 'Tanggal expired deposit wajib diisi untuk opsi custom.',
            ]);
        }

        return now()->addDays($jumlahHari)->toDateString();
    }

    protected function syncJenisTransaksiToInvoiceItems(PembayaranInvoice $invoice, int $jenisTransaksi): void
    {
        $invoice->items()
            ->where(function ($q) {
                $q->whereNull('is_delete')->orWhere('is_delete', 0);
            })
            ->lockForUpdate()
            ->update($this->onlyExistingColumns('pembayaran_invoice_item', [
                'jenis_transaksi' => $jenisTransaksi,
                'updated_by' => $this->username(),
                'updated_at' => now(),
            ]));

        $invoice->load('items');
    }

    protected function applyDepositTransactionRules(PembayaranInvoice $invoice, Request $request, ?string $depositExpiredAt, array $selectedItemIds = []): void
    {
        $invoice->update($this->onlyExistingColumns('pembayaran_invoice', [
            'dokter_id' => null,
            'referensi_dokter_id' => $request->input('referensi_dokter_id'),
            'deposit_expired_option_id' => $request->input('deposit_expired_option_id'),
            'deposit_expired_at' => $depositExpiredAt,
            'jenis_transaksi' => 4,
            'updated_by' => $this->username(),
            'updated_at' => now(),
        ]));

        $invoice->items()
            ->where(function ($q) {
                $q->whereNull('is_delete')->orWhere('is_delete', 0);
            })
            ->where('item_type', 2)
            ->when(count($selectedItemIds) > 0, fn ($q) => $q->whereIn('id', $selectedItemIds))
            ->lockForUpdate()
            ->update($this->onlyExistingColumns('pembayaran_invoice_item', [
                'jenis_transaksi' => 4,
                'dokter_id' => null,
                'perawat_id' => null,
                'expired_at' => $depositExpiredAt,
                'updated_by' => $this->username(),
                'updated_at' => now(),
            ]));

        $invoice->refresh();
        $invoice->load('items');
    }

    protected function generateDepositTreatmentRecords(PembayaranInvoice $invoice, array $selectedItems = []): void
    {
        if (!Schema::hasTable('pembayaran_deposit_treatment')) {
            throw ValidationException::withMessages([
                'deposit' => 'Tabel pembayaran_deposit_treatment belum tersedia.',
            ]);
        }

        $selectedItemIds = array_keys($selectedItems);

        if (count($selectedItemIds) === 0) {
            throw ValidationException::withMessages([
                'deposit_treatment_items' => 'Pilih minimal satu treatment yang akan dijadikan deposit.',
            ]);
        }

        $items = $invoice->items()
            ->where(function ($q) {
                $q->whereNull('is_delete')->orWhere('is_delete', 0);
            })
            ->where(function ($q) {
                $q->whereNull('status')->orWhere('status', '!=', 9);
            })
            ->where('item_type', 2)
            ->whereIn('id', $selectedItemIds)
            ->lockForUpdate()
            ->get();

        if ($items->count() !== count($selectedItemIds)) {
            throw ValidationException::withMessages([
                'deposit_treatment_items' => 'Pilihan treatment deposit tidak valid untuk invoice ini.',
            ]);
        }

        foreach ($items as $item) {
            $invoiceQty = (float) ($item->qty ?? 0);

            if ($invoiceQty <= 0) {
                $invoiceQty = 1;
            }

            $selectedQty = (float) ($selectedItems[(int) $item->id] ?? 0);

            if ($selectedQty <= 0) {
                $selectedQty = $invoiceQty;
            }

            if ($selectedQty > $invoiceQty) {
                throw ValidationException::withMessages([
                    'deposit_treatment_items' => 'Qty deposit untuk ' . ($item->nama_item ?? 'treatment') . ' tidak boleh melebihi qty invoice.',
                ]);
            }

            $selectedQty = min(max($selectedQty, 1), $invoiceQty);

            $itemNetSubtotal = $this->resolveDepositItemNetSubtotal($item);

            $hargaSatuan = $invoiceQty > 0
                ? round($itemNetSubtotal / $invoiceQty, 2)
                : 0;

            $totalNilai = round($hargaSatuan * $selectedQty, 2);

            if ($totalNilai <= 0) {
                throw ValidationException::withMessages([
                    'deposit_treatment_items' => 'Nilai deposit untuk ' . ($item->nama_item ?? 'treatment') . ' tidak valid.',
                ]);
            }

            $deposit = PembayaranDepositTreatment::query()
                ->where('pembayaran_item_id', $item->id)
                ->lockForUpdate()
                ->first();

            $payload = $this->onlyExistingColumns('pembayaran_deposit_treatment', [
                'pembayaran_id' => $invoice->id,
                'pembayaran_item_id' => $item->id,
                'pasien_id' => $invoice->pasien_id,
                'toko_beli_id' => $invoice->toko_id,
                'treatment_id' => $item->treatment_id ?? $item->item_id ?? 0,
                'treatment_toko_id' => $item->treatment_toko_id ?? null,
                'nama_treatment' => $item->nama_item ?? '-',
                'qty_total' => $selectedQty,
                'qty_claimed' => 0,
                'qty_sisa' => $selectedQty,
                'harga_satuan' => $hargaSatuan,
                'total_nilai' => $totalNilai,
                'nilai_claimed' => 0,
                'nilai_sisa' => $totalNilai,
                'expired_at' => $invoice->deposit_expired_at,
                'referensi_dokter_id' => $invoice->referensi_dokter_id,
                'claim_scope' => 2,
                'status' => 1,
                'is_delete' => 0,
                'created_by' => $this->username(),
                'updated_by' => $this->username(),
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            if ($deposit) {
                unset($payload['created_by'], $payload['created_at']);
                $deposit->update($payload);
            } else {
                $deposit = PembayaranDepositTreatment::query()->create($payload);
            }

            $item->update($this->onlyExistingColumns('pembayaran_invoice_item', [
                'deposit_treatment_id' => $deposit->id,
                'expired_at' => $invoice->deposit_expired_at,
                'updated_by' => $this->username(),
                'updated_at' => now(),
            ]));
        }
    }

    protected function resolveDepositItemNetSubtotal($item): float
    {
        $subtotalAfterDiskonSubtotal = (float) ($item->subtotal_after_diskon_subtotal ?? 0);

        if ($subtotalAfterDiskonSubtotal > 0) {
            return $subtotalAfterDiskonSubtotal;
        }

        $subtotalBeforeDiskonSubtotal = (float) ($item->subtotal_before_diskon_subtotal ?? 0);
        $diskonSubtotalAmount = (float) ($item->diskon_subtotal_amount ?? 0);

        if ($subtotalBeforeDiskonSubtotal > 0) {
            return max($subtotalBeforeDiskonSubtotal - $diskonSubtotalAmount, 0);
        }

        $subtotal = (float) ($item->subtotal ?? 0);

        return max($subtotal, 0);
    }
    
    protected function processDepositClaimsFromInvoice(PembayaranInvoice $invoice, RegistrasiKunjungan $registrasi): void
    {
        if (!Schema::hasTable('pembayaran_deposit_treatment') || !Schema::hasTable('pembayaran_deposit_treatment_claim')) {
            return;
        }

        $items = $invoice->items()
            ->where(function ($q) {
                $q->whereNull('is_delete')->orWhere('is_delete', 0);
            })
            ->where('item_type', 2)
            ->whereNotNull('deposit_treatment_id')
            ->lockForUpdate()
            ->get();

        foreach ($items as $item) {
            $depositId = (int) ($item->deposit_treatment_id ?? 0);
            if ($depositId <= 0) {
                continue;
            }

            $deposit = DB::table('pembayaran_deposit_treatment')
                ->where('id', $depositId)
                ->where(function ($q) {
                    $q->whereNull('is_delete')->orWhere('is_delete', 0);
                })
                ->lockForUpdate()
                ->first();

            if (!$deposit) {
                throw ValidationException::withMessages([
                    'deposit' => 'Data deposit treatment tidak ditemukan untuk item ' . ($item->nama_item ?? '-'),
                ]);
            }

            if (!empty($deposit->expired_at) && Carbon::parse($deposit->expired_at)->lt(Carbon::today())) {
                throw ValidationException::withMessages([
                    'deposit' => 'Deposit treatment sudah expired untuk item ' . ($item->nama_item ?? '-'),
                ]);
            }

            $treatmentIdItem = (int) ($item->treatment_id ?? 0);
            $treatmentIdDeposit = (int) ($deposit->treatment_id ?? 0);
            if ($treatmentIdItem > 0 && $treatmentIdDeposit > 0 && $treatmentIdItem !== $treatmentIdDeposit) {
                throw ValidationException::withMessages([
                    'deposit' => 'Treatment yang diklaim tidak sesuai dengan deposit yang tersedia.',
                ]);
            }

            $qtyClaim = max((float) ($item->qty ?? 1), 1);
            $qtySisa = (float) ($deposit->qty_sisa ?? 0);
            if ($qtySisa < $qtyClaim) {
                throw ValidationException::withMessages([
                    'deposit' => 'Qty deposit tidak mencukupi untuk item ' . ($item->nama_item ?? '-'),
                ]);
            }

            $hargaSatuan = (float) ($deposit->harga_satuan ?? 0);
            $nilaiRealisasi = round($qtyClaim * $hargaSatuan, 2);
            $nilaiSisa = (float) ($deposit->nilai_sisa ?? 0);
            if ($nilaiSisa > 0) {
                $nilaiRealisasi = min($nilaiRealisasi, $nilaiSisa);
            }

            $existingClaim = DB::table('pembayaran_deposit_treatment_claim')
                ->where('pembayaran_item_id', $item->id)
                ->where(function ($q) {
                    $q->whereNull('is_delete')->orWhere('is_delete', 0);
                })
                ->lockForUpdate()
                ->first();

            if ($existingClaim) {
                $claimId = $existingClaim->id;
                continue;
            }

            $claimId = DB::table('pembayaran_deposit_treatment_claim')->insertGetId($this->onlyExistingColumns('pembayaran_deposit_treatment_claim', [
                'deposit_treatment_id' => $depositId,
                'registrasi_id' => $registrasi->id,
                'pembayaran_id' => $invoice->id,
                'pembayaran_item_id' => $item->id,
                'toko_claim_id' => $invoice->toko_id,
                'treatment_detail_id' => $item->source_detail_id ?? null,
                'qty_claim' => $qtyClaim,
                'nilai_realisasi' => $nilaiRealisasi,
                'claim_dokter_id' => $item->dokter_id ?? $registrasi->dokter_awal_id ?? null,
                'claim_perawat_id' => $item->perawat_id ?? $registrasi->perawat_awal_id ?? null,
                'claimed_at' => now(),
                'status' => PembayaranDepositTreatmentClaim::STATUS_AKTIF,
                'is_delete' => 0,
                'created_by' => $this->username(),
                'updated_by' => $this->username(),
                'created_at' => now(),
                'updated_at' => now(),
            ]));

            DB::table('pembayaran_deposit_treatment')
                ->where('id', $depositId)
                ->update($this->onlyExistingColumns('pembayaran_deposit_treatment', [
                    'qty_claimed' => (float) ($deposit->qty_claimed ?? 0) + $qtyClaim,
                    'qty_sisa' => max($qtySisa - $qtyClaim, 0),
                    'nilai_claimed' => (float) ($deposit->nilai_claimed ?? 0) + $nilaiRealisasi,
                    'nilai_sisa' => max($nilaiSisa - $nilaiRealisasi, 0),
                    'status' => max($qtySisa - $qtyClaim, 0) <= 0 ? 2 : ($deposit->status ?? 1),
                    'updated_by' => $this->username(),
                    'updated_at' => now(),
                ]));

            $item->update($this->onlyExistingColumns('pembayaran_invoice_item', [
                'deposit_claim_id' => $claimId,
                'subtotal' => 0,
                'updated_by' => $this->username(),
                'updated_at' => now(),
            ]));
        }

        $invoice->load('items');
    }

    protected function applySelectedPromosFromRequest(Request $request, PembayaranInvoice $invoice): void
    {
        if (!Schema::hasTable('pembayaran_invoice_promo') || !Schema::hasTable('master_voucher_diskon')) {
            return;
        }

        $references = $this->normalizeSelectedPromoReferences($request);
        if (count($references) === 0) {
            return;
        }

        $items = $invoice->items()
            ->where(function ($q) {
                $q->whereNull('is_delete')->orWhere('is_delete', 0);
            })
            ->lockForUpdate()
            ->get();

        $this->resetInvoicePromoRows($invoice);

        $selectedVouchers = [];
        foreach ($references as $reference) {
            $voucher = $this->findVoucherByReference($reference);
            if (!$voucher) {
                throw ValidationException::withMessages([
                    'promo' => 'Voucher tidak ditemukan atau tidak aktif.',
                ]);
            }
            $selectedVouchers[$voucher->id] = $voucher;
        }

        $nonCombine = collect($selectedVouchers)->filter(fn ($voucher) => (int) ($voucher->is_bisa_digabung_promo ?? 0) !== 1);
        if ($nonCombine->count() > 0 && count($selectedVouchers) > 1) {
            throw ValidationException::withMessages([
                'promo' => 'Voucher tidak bisa digabung dengan promo lain.',
            ]);
        }

        $totalPromo = 0;
        foreach ($selectedVouchers as $voucher) {
            $this->validateVoucherForInvoice($voucher, $invoice);
            $amount = $this->calculateVoucherAmount($voucher, $items);
            if ($amount <= 0) {
                throw ValidationException::withMessages([
                    'promo' => 'Voucher ' . ($voucher->nama_voucher ?? $voucher->kode_voucher ?? '-') . ' tidak sesuai dengan item transaksi.',
                ]);
            }

            $remainingBase = max((float) $items->sum(fn ($item) => (float) ($item->subtotal ?? 0)) - $totalPromo, 0);
            $amount = min($amount, $remainingBase);
            if ($amount <= 0) {
                continue;
            }

            $this->createInvoicePromoRow($invoice, $voucher, $amount);
            $this->redeemVoucherCodeIfNeeded($voucher, $invoice);
            $this->consumeVoucherQuotaIfNeeded($voucher, $invoice);
            $totalPromo += $amount;
        }

        $invoice->update($this->onlyExistingColumns('pembayaran_invoice', [
            'total_promo' => $totalPromo,
            'diskon_promo' => $totalPromo,
            'updated_by' => $this->username(),
            'updated_at' => now(),
        ]));
    }

    protected function applySubtotalDiscountFromRequest(Request $request, PembayaranInvoice $invoice): void
    {
        $this->subtotalDiscountService->applyFromRequest($request, $invoice);
    }

    protected function normalizeSelectedPromoReferences(Request $request): array
    {
        $references = [];

        foreach ((array) $request->input('promo_ids', []) as $id) {
            if ($id) {
                $references[] = ['id' => (int) $id];
            }
        }

        foreach (['promos', 'selected_promos', 'applied_promos'] as $key) {
            $rows = $request->input($key, []);
            if (!is_array($rows)) {
                continue;
            }

            foreach ($rows as $row) {
                if (!is_array($row)) {
                    continue;
                }

                $references[] = [
                    'id' => $row['id'] ?? $row['voucher_id'] ?? $row['master_voucher_diskon_id'] ?? null,
                    'kode' => $row['kode_voucher'] ?? $row['kode'] ?? $row['code'] ?? null,
                ];
            }
        }

        if ($request->filled('promo_code')) {
            $references[] = ['kode' => $request->input('promo_code')];
        }

        return collect($references)
            ->filter(fn ($row) => !empty($row['id']) || !empty($row['kode']))
            ->unique(fn ($row) => ($row['id'] ?? '') . '|' . ($row['kode'] ?? ''))
            ->values()
            ->all();
    }

    protected function findVoucherByReference(array $reference): ?object
    {
        $query = DB::table('master_voucher_diskon')
            ->where(function ($q) {
                $q->whereNull('is_delete')->orWhere('is_delete', 0);
            })
            ->where(function ($q) {
                $q->whereNull('status_voucher')->orWhere('status_voucher', 1);
            });

        if (!empty($reference['id'])) {
            $query->where('id', (int) $reference['id']);
        } elseif (!empty($reference['kode'])) {
            $kode = trim((string) $reference['kode']);
            $query->where(function ($q) use ($kode) {
                $q->where('kode_voucher', $kode);

                if (Schema::hasTable('master_voucher_diskon_kode')) {
                    $voucherId = DB::table('master_voucher_diskon_kode')
                        ->where('kode_voucher', $kode)
                        ->where(function ($sub) {
                            $sub->whereNull('is_delete')->orWhere('is_delete', 0);
                        })
                        ->value('voucher_diskon_id');

                    if ($voucherId) {
                        $q->orWhere('id', $voucherId);
                    }
                }
            });
        }

        return $query->lockForUpdate()->first();
    }

    protected function validateVoucherForInvoice(object $voucher, PembayaranInvoice $invoice): void
    {
        if ((int) ($voucher->is_all_toko ?? 0) !== 1 && !empty($voucher->toko_id) && (int) $voucher->toko_id !== (int) $invoice->toko_id) {
            throw ValidationException::withMessages([
                'promo' => 'Voucher tidak berlaku untuk cabang transaksi ini.',
            ]);
        }

        if ((int) ($voucher->is_unlimited_date ?? 0) !== 1) {
            $today = Carbon::today();
            if (!empty($voucher->tanggal_mulai) && Carbon::parse($voucher->tanggal_mulai)->gt($today)) {
                throw ValidationException::withMessages(['promo' => 'Voucher belum aktif.']);
            }
            if (!empty($voucher->tanggal_akhir) && Carbon::parse($voucher->tanggal_akhir)->lt($today)) {
                throw ValidationException::withMessages(['promo' => 'Voucher sudah expired.']);
            }
        }

        if ((int) ($voucher->is_unlimited_generate ?? 0) !== 1 && (int) ($voucher->qty_generate ?? 0) <= 0) {
            throw ValidationException::withMessages([
                'promo' => 'Kuota voucher ' . ($voucher->nama_voucher ?? $voucher->kode_voucher ?? '-') . ' sudah habis.',
            ]);
        }

        if (Schema::hasTable('master_voucher_diskon_kode')) {
            $kodeRows = DB::table('master_voucher_diskon_kode')
                ->where('voucher_diskon_id', $voucher->id)
                ->where(function ($q) {
                    $q->whereNull('is_delete')->orWhere('is_delete', 0);
                })
                ->lockForUpdate()
                ->get();

            if ($kodeRows->isNotEmpty()) {
                $available = $kodeRows->contains(function ($row) {
                    return (int) ($row->status_kode ?? 0) === 1
                        && (empty($row->expired_at) || Carbon::parse($row->expired_at)->gte(Carbon::today()));
                });

                $alreadyForThisInvoice = $kodeRows->contains(function ($row) use ($invoice) {
                    return (int) ($row->status_kode ?? 0) === 2
                        && (string) ($row->redeemed_invoice_no ?? '') === (string) $invoice->no_invoice;
                });

                if (!$available && !$alreadyForThisInvoice) {
                    throw ValidationException::withMessages([
                        'promo' => 'Kode voucher sudah tidak tersedia atau sudah digunakan.',
                    ]);
                }
            }
        }
    }

    protected function calculateVoucherAmount(object $voucher, $items): float
    {
        $eligibleBase = $this->resolveVoucherEligibleBase($voucher, $items);
        if ($eligibleBase <= 0) {
            return 0;
        }

        $tipe = strtolower((string) ($voucher->tipe_diskon ?? 'nominal'));
        $nilai = (float) ($voucher->total_diskon ?? 0);

        if ($tipe === 'percent' || $tipe === 'persen' || $tipe === '%') {
            $amount = round(($eligibleBase * $nilai) / 100, 2);
            $max = (float) ($voucher->total_diskon_maksimal ?? 0);
            if ($max > 0) {
                $amount = min($amount, $max);
            }
        } else {
            $amount = $nilai;
        }

        return min(max($amount, 0), $eligibleBase);
    }

    protected function resolveVoucherEligibleBase(object $voucher, $items): float
    {
        $baseItems = collect($items);

        if (Schema::hasTable('master_voucher_diskon_item')) {
            $voucherItems = DB::table('master_voucher_diskon_item')
                ->where('voucher_diskon_id', $voucher->id)
                ->where(function ($q) {
                    $q->whereNull('is_delete')->orWhere('is_delete', 0);
                })
                ->get();

            if ($voucherItems->isNotEmpty()) {
                $baseItems = $baseItems->filter(function ($item) use ($voucherItems) {
                    return $voucherItems->contains(function ($rule) use ($item) {
                        if ($rule->item_type === 'produk') {
                            return $this->isProductItem($item) && (int) ($item->produk_id ?? 0) === (int) $rule->item_id;
                        }
                        if ($rule->item_type === 'treatment') {
                            return $this->isTreatmentItem($item) && (int) ($item->treatment_id ?? 0) === (int) $rule->item_id;
                        }
                        return false;
                    });
                });
            }
        }

        return (float) $baseItems->sum(fn ($item) => (float) ($item->subtotal ?? 0));
    }

    protected function normalizePromoDiscountType($value): int
    {
        $text = strtolower(trim((string) $value));

        if (in_array($text, ['1', 'percent', 'persen', '%'], true)) {
            return 1;
        }

        if (in_array($text, ['2', 'nominal', 'rupiah', 'rp'], true)) {
            return 2;
        }

        return 2;
    }

    protected function createInvoicePromoRow(PembayaranInvoice $invoice, object $voucher, float $amount): void
    {
        DB::table('pembayaran_invoice_promo')->insert($this->onlyExistingColumns('pembayaran_invoice_promo', [
            'pembayaran_id' => $invoice->id,
            'voucher_id' => $voucher->id,
            'kode_voucher' => $voucher->kode_voucher ?? null,
            'nama_voucher' => $voucher->nama_voucher ?? null,
            'scope_type' => $voucher->jenis_voucher_id ?? null,
            'diskon_tipe' => $this->normalizePromoDiscountType($voucher->tipe_diskon ?? $voucher->diskon_tipe ?? 2),
            'diskon_nilai' => $voucher->total_diskon ?? 0,
            'diskon_amount' => $amount,
            'catatan' => 'Redeem saat submit pembayaran',
            'is_delete' => 0,
            'created_by' => $this->username(),
            'updated_by' => $this->username(),
            'created_at' => now(),
            'updated_at' => now(),
        ]));
    }

    protected function resetInvoicePromoRows(PembayaranInvoice $invoice): void
    {
        DB::table('pembayaran_invoice_promo')
            ->where('pembayaran_id', $invoice->id)
            ->where(function ($q) {
                $q->whereNull('is_delete')->orWhere('is_delete', 0);
            })
            ->update($this->onlyExistingColumns('pembayaran_invoice_promo', [
                'is_delete' => 1,
                'updated_by' => $this->username(),
                'updated_at' => now(),
            ]));
    }

    protected function redeemVoucherCodeIfNeeded(object $voucher, PembayaranInvoice $invoice): void
    {
        if (!Schema::hasTable('master_voucher_diskon_kode')) {
            return;
        }

        $row = DB::table('master_voucher_diskon_kode')
            ->where('voucher_diskon_id', $voucher->id)
            ->where(function ($q) {
                $q->whereNull('is_delete')->orWhere('is_delete', 0);
            })
            ->where(function ($q) use ($invoice) {
                $q->where('status_kode', 1)
                    ->orWhere(function ($sub) use ($invoice) {
                        $sub->where('status_kode', 2)
                            ->where('redeemed_invoice_no', $invoice->no_invoice);
                    });
            })
            ->orderBy('id')
            ->lockForUpdate()
            ->first();

        if (!$row) {
            return;
        }

        DB::table('master_voucher_diskon_kode')
            ->where('id', $row->id)
            ->update($this->onlyExistingColumns('master_voucher_diskon_kode', [
                'status_kode' => 2,
                'used_at' => now(),
                'redeemed_invoice_no' => $invoice->no_invoice,
                'redeemed_pasien_id' => $invoice->pasien_id,
                'updated_by' => $this->username(),
                'updated_at' => now(),
            ]));
    }


    protected function resolveDepositItemIds(Request $request, PembayaranInvoice $invoice): array
    {
        return array_keys($this->resolveDepositItems($request, $invoice));
    }

    protected function resolveDepositItems(Request $request, PembayaranInvoice $invoice): array
    {
        $payloadRows = array_merge(
            (array) $request->input('deposit_items', []),
            (array) $request->input('deposit_treatment_items', [])
        );

        foreach (array_merge(
            (array) $request->input('deposit_item_ids', []),
            (array) $request->input('deposit_treatment_item_ids', [])
        ) as $id) {
            $itemId = (int) $id;

            if ($itemId > 0) {
                $payloadRows[] = [
                    'item_id' => $itemId,
                    'invoice_item_id' => $itemId,
                    'qty_deposit' => 0,
                ];
            }
        }

        foreach ((array) $request->input('deposit_item_quantities', []) as $itemId => $qty) {
            $itemId = (int) $itemId;
            $qty = (float) $qty;

            if ($itemId > 0) {
                $payloadRows[] = [
                    'item_id' => $itemId,
                    'invoice_item_id' => $itemId,
                    'qty_deposit' => $qty,
                ];
            }
        }

        if (count($payloadRows) === 0) {
            return [];
        }

        $activeItems = $invoice->items()
            ->where(function ($q) {
                $q->whereNull('is_delete')->orWhere('is_delete', 0);
            })
            ->where(function ($q) {
                $q->whereNull('status')->orWhere('status', '!=', 9);
            })
            ->lockForUpdate()
            ->get()
            ->filter(fn ($item) => $this->isTreatmentItem($item))
            ->values();

        $selected = [];
        $usedItemIds = [];

        foreach ($payloadRows as $row) {
            if (!is_array($row)) {
                continue;
            }

            $item = $this->matchDepositInvoiceItemFromPayload(
                $activeItems,
                $row,
                $usedItemIds
            );

            if (!$item) {
                continue;
            }

            $invoiceItemId = (int) $item->id;
            $invoiceQty = (float) ($item->qty ?? 0);

            if ($invoiceQty <= 0) {
                $invoiceQty = 1;
            }

            $qtyDeposit = (float) (
                $row['qty_deposit']
                ?? $row['qty']
                ?? $row['jumlah']
                ?? 0
            );

            if ($qtyDeposit <= 0) {
                $qtyDeposit = $invoiceQty;
            }

            if ($qtyDeposit > $invoiceQty) {
                throw ValidationException::withMessages([
                    'deposit_treatment_items' => 'Qty deposit untuk ' . ($item->nama_item ?? 'treatment') . ' tidak boleh melebihi qty invoice.',
                ]);
            }

            $selected[$invoiceItemId] = min(max($qtyDeposit, 1), $invoiceQty);
            $usedItemIds[] = $invoiceItemId;
        }

        return $selected;
    }
    
    protected function matchDepositInvoiceItemFromPayload($activeItems, array $row, array $usedItemIds = [])
    {
        $candidateItemId = (int) (
            $row['item_id']
            ?? $row['pembayaran_item_id']
            ?? $row['invoice_item_id']
            ?? $row['id']
            ?? 0
        );

        if ($candidateItemId > 0) {
            $item = $activeItems->first(function ($item) use ($candidateItemId, $usedItemIds) {
                return (int) ($item->id ?? 0) === $candidateItemId
                    && !in_array((int) ($item->id ?? 0), $usedItemIds, true);
            });

            if ($item) {
                return $item;
            }
        }

        $sourceDetailId = (int) (
            $row['source_detail_id']
            ?? $row['registrasi_treatment_detail_id']
            ?? 0
        );

        if ($sourceDetailId > 0) {
            $item = $activeItems->first(function ($item) use ($sourceDetailId, $usedItemIds) {
                return (int) ($item->source_detail_id ?? 0) === $sourceDetailId
                    && !in_array((int) ($item->id ?? 0), $usedItemIds, true);
            });

            if ($item) {
                return $item;
            }
        }

        $treatmentTokoId = (int) ($row['treatment_toko_id'] ?? 0);
        $treatmentId = (int) ($row['treatment_id'] ?? 0);

        if ($treatmentTokoId > 0 && $treatmentId > 0) {
            $item = $activeItems->first(function ($item) use ($treatmentTokoId, $treatmentId, $usedItemIds) {
                return (int) ($item->treatment_toko_id ?? 0) === $treatmentTokoId
                    && (int) ($item->treatment_id ?? 0) === $treatmentId
                    && !in_array((int) ($item->id ?? 0), $usedItemIds, true);
            });

            if ($item) {
                return $item;
            }
        }

        if ($treatmentTokoId > 0) {
            $item = $activeItems->first(function ($item) use ($treatmentTokoId, $usedItemIds) {
                return (int) ($item->treatment_toko_id ?? 0) === $treatmentTokoId
                    && !in_array((int) ($item->id ?? 0), $usedItemIds, true);
            });

            if ($item) {
                return $item;
            }
        }

        if ($treatmentId > 0) {
            return $activeItems->first(function ($item) use ($treatmentId, $usedItemIds) {
                return (int) ($item->treatment_id ?? 0) === $treatmentId
                    && !in_array((int) ($item->id ?? 0), $usedItemIds, true);
            });
        }

        return null;
    }
    protected function updatePatientPhoneFromRequest(Request $request, PembayaranInvoice $invoice): void
    {
        $this->paymentPatientService->updatePhoneFromRequest($request, $invoice);
    }

    protected function normalizeIndonesianPhone($value): ?string
    {
        $digits = preg_replace('/[^0-9]/', '', (string) $value);
        if ($digits === '') {
            return null;
        }

        if (str_starts_with($digits, '62')) {
            return $digits;
        }

        if (str_starts_with($digits, '0')) {
            return '62' . substr($digits, 1);
        }

        if (str_starts_with($digits, '8')) {
            return '62' . $digits;
        }

        return $digits;
    }

    protected function resolveIsPremierFromRequest(
        Request $request,
        ?PembayaranInvoice $invoice = null
    ): int {
        if ($request->has('is_premier')) {
            return $request->boolean('is_premier') ? 1 : 0;
        }

        return (int) ($invoice?->is_premier ?? 0) === 1 ? 1 : 0;
    }

    protected function consumeVoucherQuotaIfNeeded(object $voucher, PembayaranInvoice $invoice): void
    {
        if (!Schema::hasTable('master_voucher_diskon')) {
            return;
        }

        if ((int) ($voucher->is_unlimited_generate ?? 0) === 1) {
            return;
        }

        $affected = DB::table('master_voucher_diskon')
            ->where('id', $voucher->id)
            ->where(function ($q) {
                $q->whereNull('is_delete')->orWhere('is_delete', 0);
            })
            ->where('qty_generate', '>', 0)
            ->decrement('qty_generate', 1, $this->onlyExistingColumns('master_voucher_diskon', [
                'updated_by' => $this->username(),
                'updated_at' => now(),
            ]));

        if ($affected < 1) {
            throw ValidationException::withMessages([
                'promo' => 'Kuota voucher ' . ($voucher->nama_voucher ?? $voucher->kode_voucher ?? '-') . ' sudah habis.',
            ]);
        }
    }

    protected function restoreVoucherQuotaFromInvoice(PembayaranInvoice $invoice): void
    {
        if (!Schema::hasTable('pembayaran_invoice_promo') || !Schema::hasTable('master_voucher_diskon')) {
            return;
        }

        $voucherIds = DB::table('pembayaran_invoice_promo')
            ->where('pembayaran_id', $invoice->id)
            ->whereNotNull('voucher_id')
            ->where(function ($q) {
                $q->whereNull('is_delete')->orWhere('is_delete', 0);
            })
            ->pluck('voucher_id')
            ->map(fn ($id) => (int) $id)
            ->unique()
            ->values();

        foreach ($voucherIds as $voucherId) {
            $voucher = DB::table('master_voucher_diskon')
                ->where('id', $voucherId)
                ->lockForUpdate()
                ->first();

            if (!$voucher || (int) ($voucher->is_unlimited_generate ?? 0) === 1) {
                continue;
            }

            DB::table('master_voucher_diskon')
                ->where('id', $voucherId)
                ->increment('qty_generate', 1, $this->onlyExistingColumns('master_voucher_diskon', [
                    'updated_by' => $this->username(),
                    'updated_at' => now(),
                ]));
        }
    }

    protected function createAccurateSyncPendingLog(PembayaranInvoice $invoice, RegistrasiKunjungan $registrasi): void
    {
        if (!Schema::hasTable('accurate_sync_log')) {
            return;
        }

        $items = $invoice->items()
            ->where(function ($q) {
                $q->whereNull('is_delete')->orWhere('is_delete', 0);
            })
            ->get()
            ->filter(function ($item) {
                $send = (int) ($item->is_send_to_accurate ?? 0) === 1;
                $sendZero = (int) ($item->send_when_zero ?? 0) === 1;
                $subtotal = (float) ($item->subtotal ?? 0);
                return $send && ($subtotal > 0 || $sendZero);
            })
            ->values();

        if ($items->isEmpty()) {
            return;
        }

        $payload = $this->buildAccuratePendingPayload($invoice, $registrasi, $items);

        $existing = DB::table('accurate_sync_log')
            ->where('pembayaran_id', $invoice->id)
            ->where('sync_type', 'sales_invoice')
            ->whereIn('status', [0, 2])
            ->lockForUpdate()
            ->first();

        $data = $this->onlyExistingColumns('accurate_sync_log', [
            'pembayaran_id' => $invoice->id,
            'no_invoice' => $invoice->no_invoice,
            'sync_type' => 'sales_invoice',
            'status' => 0,
            'request_payload' => json_encode($payload, JSON_UNESCAPED_UNICODE),
            'error_message' => null,
            'updated_at' => now(),
            'created_at' => now(),
        ]);

        if ($existing) {
            unset($data['created_at']);
            DB::table('accurate_sync_log')->where('id', $existing->id)->update($data);
        } else {
            DB::table('accurate_sync_log')->insert($data);
        }
    }

    protected function buildAccuratePendingPayload(PembayaranInvoice $invoice, RegistrasiKunjungan $registrasi, $items): array
    {
        return [
            'no_invoice' => $invoice->no_invoice,
            'tanggal_invoice' => $invoice->tanggal_invoice,
            'tanggal_lunas' => now()->toDateTimeString(),
            'toko_id' => $invoice->toko_id,
            'registrasi_id' => $invoice->registrasi_id,
            'kode_registrasi' => $invoice->kode_registrasi,
            'pasien_id' => $invoice->pasien_id,
            'jenis_transaksi' => (int) ($invoice->jenis_transaksi ?? 0),
            'is_premier' => (int) ($invoice->is_premier ?? 0),
            'grand_total' => (float) ($invoice->grand_total ?? 0),
            'items' => $items->map(function ($item) {
                return [
                    'item_id' => $item->id,
                    'item_type' => $item->item_type,
                    'nama_item' => $item->nama_item,
                    'qty' => (float) ($item->qty ?? 0),
                    'harga' => (float) ($item->harga ?? 0),
                    'subtotal' => (float) ($item->subtotal ?? 0),
                    'accurate_mapping_id' => $item->accurate_mapping_id,
                    'accurate_source_type' => $item->accurate_source_type,
                    'accurate_source_code' => $item->accurate_source_code,
                    'kode_accurate' => $item->kode_accurate_snapshot,
                    'nama_accurate' => $item->nama_accurate_snapshot,
                    'send_when_zero' => (int) ($item->send_when_zero ?? 0),
                ];
            })->values()->all(),
        ];
    }

    protected function rollbackPaymentSideEffects(PembayaranInvoice $invoice, string $reason): void
    {
        $this->voidPaymentStockMutations($invoice, $reason);
        $this->releasePaymentReservations($invoice, $reason);
        $this->rollbackDepositPurchaseRecords($invoice);
        $this->rollbackDepositClaimRecords($invoice);
        $this->cancelAccurateSyncLog($invoice, $reason);
        $this->memberPointService->rollbackPointLedger($invoice, $reason, $this->username());
    }

    protected function voidPaymentStockMutations(PembayaranInvoice $invoice, string $reason): void
    {
        if (!Schema::hasTable('stock_mutasi_produk')) {
            return;
        }

        DB::table('stock_mutasi_produk')
            ->where('ref_type', 'PEMBAYARAN')
            ->where('ref_table', 'pembayaran_invoice')
            ->where('ref_id', $invoice->id)
            ->where(function ($q) {
                $q->whereNull('is_void')->orWhere('is_void', 0);
            })
            ->update($this->onlyExistingColumns('stock_mutasi_produk', [
                'is_void' => 1,
                'void_reason' => $reason,
            ]));
    }

    protected function releasePaymentReservations(PembayaranInvoice $invoice, string $reason): void
    {
        if (!Schema::hasTable('stock_reservasi_produk')) {
            return;
        }

        $reservationIds = $invoice->items()
            ->whereNotNull('stock_reservasi_id')
            ->pluck('stock_reservasi_id')
            ->filter()
            ->unique()
            ->values()
            ->all();

        if (count($reservationIds) === 0) {
            return;
        }

        DB::table('stock_reservasi_produk')
            ->whereIn('id', $reservationIds)
            ->whereIn('status', [0, 1])
            ->update($this->onlyExistingColumns('stock_reservasi_produk', [
                'status' => 3,
                'released_at' => now(),
                'keterangan' => $reason,
                'updated_by' => $this->username(),
                'updated_at' => now(),
            ]));
    }

    protected function rollbackVoucherRedeem(PembayaranInvoice $invoice): void
    {
        $this->restoreVoucherQuotaFromInvoice($invoice);

        if (Schema::hasTable('master_voucher_diskon_kode')) {
            DB::table('master_voucher_diskon_kode')
                ->where('redeemed_invoice_no', $invoice->no_invoice)
                ->update($this->onlyExistingColumns('master_voucher_diskon_kode', [
                    'status_kode' => 1,
                    'used_at' => null,
                    'redeemed_invoice_no' => null,
                    'redeemed_pasien_id' => null,
                    'updated_by' => $this->username(),
                    'updated_at' => now(),
                ]));
        }

        if (Schema::hasTable('pembayaran_invoice_promo')) {
            DB::table('pembayaran_invoice_promo')
                ->where('pembayaran_id', $invoice->id)
                ->update($this->onlyExistingColumns('pembayaran_invoice_promo', [
                    'is_delete' => 1,
                    'updated_by' => $this->username(),
                    'updated_at' => now(),
                ]));
        }
    }

    protected function rollbackDepositPurchaseRecords(PembayaranInvoice $invoice): void
    {
        if (!Schema::hasTable('pembayaran_deposit_treatment')) {
            return;
        }

        DB::table('pembayaran_deposit_treatment')
            ->where('pembayaran_id', $invoice->id)
            ->where(function ($q) {
                $q->whereNull('is_delete')->orWhere('is_delete', 0);
            })
            ->update($this->onlyExistingColumns('pembayaran_deposit_treatment', [
                'status' => 9,
                'is_delete' => 1,
                'updated_by' => $this->username(),
                'updated_at' => now(),
            ]));
    }

    protected function rollbackDepositClaimRecords(PembayaranInvoice $invoice): void
    {
        if (!Schema::hasTable('pembayaran_deposit_treatment_claim') || !Schema::hasTable('pembayaran_deposit_treatment')) {
            return;
        }

        $claims = DB::table('pembayaran_deposit_treatment_claim')
            ->where('pembayaran_id', $invoice->id)
            ->where(function ($q) {
                $q->whereNull('is_delete')->orWhere('is_delete', 0);
            })
            ->lockForUpdate()
            ->get();

        foreach ($claims as $claim) {
            $deposit = DB::table('pembayaran_deposit_treatment')
                ->where('id', $claim->deposit_treatment_id)
                ->lockForUpdate()
                ->first();

            if ($deposit) {
                DB::table('pembayaran_deposit_treatment')
                    ->where('id', $deposit->id)
                    ->update($this->onlyExistingColumns('pembayaran_deposit_treatment', [
                        'qty_claimed' => max((float) ($deposit->qty_claimed ?? 0) - (float) ($claim->qty_claim ?? 0), 0),
                        'qty_sisa' => (float) ($deposit->qty_sisa ?? 0) + (float) ($claim->qty_claim ?? 0),
                        'nilai_claimed' => max((float) ($deposit->nilai_claimed ?? 0) - (float) ($claim->nilai_realisasi ?? 0), 0),
                        'nilai_sisa' => (float) ($deposit->nilai_sisa ?? 0) + (float) ($claim->nilai_realisasi ?? 0),
                        'status' => 1,
                        'updated_by' => $this->username(),
                        'updated_at' => now(),
                    ]));
            }
        }

        DB::table('pembayaran_deposit_treatment_claim')
            ->where('pembayaran_id', $invoice->id)
            ->update($this->onlyExistingColumns('pembayaran_deposit_treatment_claim', [
                'status' => 9,
                'is_delete' => 1,
                'updated_by' => $this->username(),
                'updated_at' => now(),
            ]));
    }

    protected function cancelAccurateSyncLog(PembayaranInvoice $invoice, string $reason): void
    {
        if (!Schema::hasTable('accurate_sync_log')) {
            return;
        }

        DB::table('accurate_sync_log')
            ->where('pembayaran_id', $invoice->id)
            ->where('status', 0)
            ->update($this->onlyExistingColumns('accurate_sync_log', [
                'status' => 2,
                'error_message' => $reason,
                'updated_at' => now(),
            ]));
    }

    protected function resolveAccurateMapping(string $sourceType, string $sourceCode): ?object
    {
        if (!Schema::hasTable('master_accurate_item_mapping')) {
            return null;
        }

        return DB::table('master_accurate_item_mapping')
            ->where(function ($q) use ($sourceType) {
                $q->where('source_type', $sourceType)
                    ->orWhere('source_type', strtoupper($sourceType));
            })
            ->where(function ($q) use ($sourceCode) {
                $q->where('source_code', $sourceCode)
                    ->orWhere('source_code', strtoupper($sourceCode))
                    ->orWhere('source_code', strtolower($sourceCode));
            })
            ->where(function ($q) {
                $q->whereNull('is_delete')->orWhere('is_delete', 0);
            })
            ->where(function ($q) {
                $q->whereNull('is_active')->orWhere('is_active', 1);
            })
            ->orderBy('sort_order')
            ->first();
    }

    protected function mappingShouldSendWhenZero(?object $mapping): bool
    {
        return $mapping && (int) ($mapping->is_send_to_accurate ?? 0) === 1 && (int) ($mapping->send_when_zero ?? 0) === 1;
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

    protected function validatePaymentAmount(float $grandTotal, float $totalBayar): void
    {
        if ($grandTotal > 0 && $totalBayar <= 0) {
            throw ValidationException::withMessages([
                'metode' => 'Metode pembayaran wajib diisi.',
            ]);
        }

        if ($totalBayar < $grandTotal) {
            throw ValidationException::withMessages([
                'jumlah_bayar' => 'Jumlah bayar kurang dari grand total.',
                'grand_total' => $grandTotal,
                'total_bayar' => $totalBayar,
                'kurang' => $grandTotal - $totalBayar,
            ]);
        }
    }

    protected function replaceInvoiceMetode(PembayaranInvoice $invoice, array $metode, int $jenisTransaksi = 0): void
    {
        $invoice->metode()
            ->where(function ($q) {
                $q->whereNull('is_delete')->orWhere('is_delete', 0);
            })
            ->lockForUpdate()
            ->get();

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
                'jenis_transaksi' => $jenisTransaksi,
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
        $items = $invoice->items()
            ->where(function ($q) {
                $q->whereNull('is_delete')->orWhere('is_delete', 0);
            })
            ->get()
            ->filter(fn ($item) => $this->isProductItem($item))
            ->values();

        if ($items->isEmpty()) {
            return;
        }

        if ($this->hasExistingPaymentStockMutation($invoice)) {
            return;
        }

        $reservedItems = $items
            ->filter(fn ($item) => !empty($item->stock_reservasi_id))
            ->values();

        $unreservedItems = $items
            ->filter(fn ($item) => empty($item->stock_reservasi_id))
            ->values();

        if ($reservedItems->isNotEmpty()) {
            $this->consumePaymentReservationsByInvoiceItems($invoice, $reservedItems);
        }

        if ($unreservedItems->isEmpty()) {
            return;
        }

        if (!method_exists($this->stockTransactionService, 'keluarPenjualanTanpaReservasi')) {
            return;
        }

        $payloadItems = $unreservedItems
            ->filter(function ($item) {
                return !empty($item->produk_toko_id)
                    && !empty($item->produk_id)
                    && !empty($item->tempat_produk_id)
                    && (float) ($item->qty ?? $item->jumlah ?? 0) > 0;
            })
            ->map(function ($item) {
                return [
                    'produk_toko_id' => $item->produk_toko_id,
                    'produk_id' => $item->produk_id,
                    'tempat_produk_id' => $item->tempat_produk_id,
                    'qty' => (float) ($item->qty ?? $item->jumlah ?? 0),
                    'harga_jual' => (float) ($item->harga ?? 0),
                    'ref_detail_id' => $item->id,
                ];
            })
            ->values()
            ->all();

        if (count($payloadItems) === 0) {
            return;
        }

        $this->stockTransactionService->keluarPenjualanTanpaReservasi($payloadItems, [
            'kode_mutasi' => $invoice->no_invoice,
            'tanggal' => now(),
            'toko_id' => $invoice->toko_id,
            'ref_type' => 'PEMBAYARAN',
            'ref_table' => 'pembayaran_invoice',
            'ref_id' => $invoice->id,
            'keterangan' => 'Stock keluar pembayaran ' . $invoice->no_invoice,
            'created_by' => $this->username(),
        ]);
    }

    protected function isProductItem($item): bool
    {
        $itemType = $item->item_type ?? $item->jenis_item ?? null;

        if (is_numeric($itemType)) {
            return (int) $itemType === 3;
        }

        $itemType = strtolower((string) $itemType);

        return in_array($itemType, ['produk', 'obat', 'penjualan'], true);
    }

    protected function isTreatmentItem($item): bool
    {
        $itemType = $item->item_type ?? $item->jenis_item ?? null;

        if (is_numeric($itemType)) {
            return (int) $itemType === 2;
        }

        $itemType = strtolower((string) $itemType);

        return in_array($itemType, ['treatment', 'perawatan'], true);
    }

    protected function consumePaymentReservationsByInvoiceItems(PembayaranInvoice $invoice, $items): void
    {
        if (!Schema::hasTable('stock_reservasi_produk')) {
            throw ValidationException::withMessages([
                'stock_reservasi' => 'Tabel stock_reservasi_produk belum tersedia.',
            ]);
        }

        foreach ($items as $item) {
            $reservationId = (int) ($item->stock_reservasi_id ?? 0);
            if ($reservationId <= 0) {
                continue;
            }

            $reservation = DB::table('stock_reservasi_produk')
                ->where('id', $reservationId)
                ->lockForUpdate()
                ->first();

            if (!$reservation) {
                throw ValidationException::withMessages([
                    'stock_reservasi' => 'Reservasi stok tidak ditemukan untuk item ' . ($item->nama_item ?? '-'),
                ]);
            }

            $this->validateReservationMatchesInvoiceItem($reservation, $item);
            $this->consumeSinglePaymentReservation($invoice, $reservation, $item);
        }
    }

    protected function validateReservationMatchesInvoiceItem(object $reservation, $item): void
    {
        $status = strtoupper((string) ($reservation->status ?? ''));
        if ($status !== 'ACTIVE') {
            throw ValidationException::withMessages([
                'stock_reservasi' => 'Reservasi stok untuk item ' . ($item->nama_item ?? '-') . ' tidak aktif.',
            ]);
        }

        $checks = [
            'produk_id' => 'produk_id',
            'produk_toko_id' => 'produk_toko_id',
            'tempat_produk_id' => 'tempat_produk_id',
        ];

        foreach ($checks as $reservationField => $itemField) {
            $reservationValue = (int) ($reservation->{$reservationField} ?? 0);
            $itemValue = (int) ($item->{$itemField} ?? 0);

            if ($reservationValue > 0 && $itemValue > 0 && $reservationValue !== $itemValue) {
                throw ValidationException::withMessages([
                    'stock_reservasi' => 'Reservasi stok tidak cocok dengan item ' . ($item->nama_item ?? '-'),
                ]);
            }
        }

        $qtyReserved = (float) ($reservation->qty_reserved ?? 0);
        $qtyItem = (float) ($item->qty ?? $item->jumlah ?? 0);

        if ($qtyReserved + 0.0001 < $qtyItem) {
            throw ValidationException::withMessages([
                'stock_reservasi' => 'Qty reservasi stok tidak mencukupi untuk item ' . ($item->nama_item ?? '-'),
            ]);
        }
    }

    protected function consumeSinglePaymentReservation(PembayaranInvoice $invoice, object $reservation, $item): void
    {
        $qty = (float) ($item->qty ?? $item->jumlah ?? 0);
        if ($qty <= 0) {
            return;
        }

        $stockBefore = 0.0;
        $stockAfter = 0.0;
        $reservedBefore = 0.0;
        $reservedAfter = 0.0;

        if (Schema::hasTable('stock_produk_toko')) {
            $stock = DB::table('stock_produk_toko')
                ->where('produk_toko_id', $reservation->produk_toko_id)
                ->where('toko_id', $reservation->toko_id)
                ->where('tempat_produk_id', $reservation->tempat_produk_id)
                ->lockForUpdate()
                ->first();

            if (!$stock) {
                throw ValidationException::withMessages([
                    'stock_reservasi' => 'Saldo stok tidak ditemukan untuk item ' . ($item->nama_item ?? '-'),
                ]);
            }

            $stockBefore = (float) ($stock->stok_akhir ?? 0);
            $reservedBefore = (float) ($stock->stok_reserved ?? 0);
            $stockAfter = max($stockBefore - $qty, 0);
            $reservedAfter = max($reservedBefore - $qty, 0);

            DB::table('stock_produk_toko')
                ->where('id', $stock->id)
                ->update($this->onlyExistingColumns('stock_produk_toko', [
                    'stok_keluar' => DB::raw('COALESCE(stok_keluar, 0) + ' . $qty),
                    'stok_akhir' => $stockAfter,
                    'stok_reserved' => $reservedAfter,
                    'last_mutation_at' => now(),
                    'updated_by' => $this->username(),
                    'updated_at' => now(),
                ]));
        }

        DB::table('stock_reservasi_produk')
            ->where('id', $reservation->id)
            ->update($this->onlyExistingColumns('stock_reservasi_produk', [
                'status' => 'CONSUMED',
                'consumed_at' => now(),
                'keterangan' => trim((string) ($reservation->keterangan ?? '') . ' | Consumed by pembayaran ' . $invoice->no_invoice),
                'updated_by' => $this->username(),
                'updated_at' => now(),
            ]));

        if (Schema::hasTable('stock_mutasi_produk')) {
            DB::table('stock_mutasi_produk')->insert($this->onlyExistingColumns('stock_mutasi_produk', [
                'kode_mutasi' => $invoice->no_invoice,
                'tanggal' => now(),
                'toko_id' => $reservation->toko_id,
                'tempat_produk_id' => $reservation->tempat_produk_id,
                'produk_toko_id' => $reservation->produk_toko_id,
                'produk_id' => $reservation->produk_id,
                'tipe_mutasi' => 'CONSUME_RESERVE',
                'arah_mutasi' => 'OUT',
                'qty_masuk' => 0,
                'qty_keluar' => $qty,
                'qty_adjustment' => 0,
                'qty_reserved_delta' => -1 * $qty,
                'stok_sebelum' => $stockBefore,
                'stok_sesudah' => $stockAfter,
                'reserved_sebelum' => $reservedBefore,
                'reserved_sesudah' => $reservedAfter,
                'harga_beli' => 0,
                'harga_jual' => (float) ($item->harga ?? 0),
                'ref_type' => 'PEMBAYARAN',
                'ref_table' => 'pembayaran_invoice',
                'ref_id' => $invoice->id,
                'ref_detail_id' => $item->id ?? null,
                'keterangan' => 'Consume reservasi stok pembayaran ' . $invoice->no_invoice,
                'is_void' => 0,
                'created_by' => $this->username(),
                'created_at' => now(),
            ]));
        }
    }

    protected function hasExistingPaymentStockMutation(PembayaranInvoice $invoice): bool
    {
        if (!Schema::hasTable('stock_mutasi_produk')) {
            return false;
        }

        return DB::table('stock_mutasi_produk')
            ->where('ref_type', 'PEMBAYARAN')
            ->where('ref_table', 'pembayaran_invoice')
            ->where('ref_id', $invoice->id)
            ->where(function ($q) {
                $q->whereNull('is_void')->orWhere('is_void', 0);
            })
            ->exists();
    }

    protected function getPaymentTask(RegistrasiKunjungan $registrasi): ?RegistrasiTask
    {
        return $registrasi->tasks
            ? $registrasi->tasks->firstWhere('task_type', RegistrasiTask::TYPE_PEMBAYARAN)
            : $registrasi->tasks()
                ->where('task_type', RegistrasiTask::TYPE_PEMBAYARAN)
                ->first();
    }

    protected function shouldSyncPendingInvoicesOnList(Request $request): bool
    {
        if ($request->boolean('sync_pending')) {
            return true;
        }

        $status = strtolower(trim((string) $request->get('status', '')));

        if (in_array($status, ['lunas', 'paid', 'selesai'], true)) {
            return false;
        }

        return true;
    }

    protected function syncPendingInvoicesLite(Request $request): void
    {
        $dateColumn = Schema::hasColumn('registrasi_kunjungan', 'tanggal_kunjungan')
            ? 'tanggal_kunjungan'
            : (Schema::hasColumn('registrasi_kunjungan', 'tanggal') ? 'tanggal' : 'created_at');

        $query = RegistrasiKunjungan::query()
            ->active()
            ->where(function ($q) {
                $q->where('current_task', RegistrasiTask::TYPE_PEMBAYARAN)
                    ->orWhereHas('tasks', function ($task) {
                        $task->where('task_type', RegistrasiTask::TYPE_PEMBAYARAN);
                    })
                    ->orWhere('is_penjualan', 1)
                    ->orWhere('is_treatment', 1)
                    ->orWhere('total_penjualan', '>', 0)
                    ->orWhere('total_treatment', '>', 0)
                    ->orWhere('total_konsultasi', '>', 0)
                    ->orWhereHas('penjualanDetails', function ($detail) {
                        $detail->where(function ($q) {
                            $q->whereNull('is_delete')->orWhere('is_delete', 0);
                        });

                        if (Schema::hasColumn('registrasi_penjualan_detail', 'jumlah')) {
                            $detail->where('jumlah', '>', 0);
                        }
                    })
                    ->orWhereHas('treatmentDetails', function ($detail) {
                        $detail->where(function ($q) {
                            $q->whereNull('is_delete')->orWhere('is_delete', 0);
                        });

                        if (Schema::hasColumn('registrasi_treatment_detail', 'jumlah')) {
                            $detail->where('jumlah', '>', 0);
                        }
                    });
            })
            ->whereNotExists(function ($sub) {
                $sub->select(DB::raw(1))
                    ->from('pembayaran_invoice')
                    ->whereColumn('pembayaran_invoice.registrasi_id', 'registrasi_kunjungan.id')
                    ->where(function ($q) {
                        $q->whereNull('pembayaran_invoice.is_delete')
                            ->orWhere('pembayaran_invoice.is_delete', 0);
                    })
                    ->whereIn('pembayaran_invoice.status', [
                        PembayaranInvoice::STATUS_MENUNGGU,
                        PembayaranInvoice::STATUS_PROSES,
                        PembayaranInvoice::STATUS_LUNAS,
                    ]);
            });

        if ($request->filled('toko_id')) {
            $query->where('toko_id', $request->toko_id);
        }

        if ($request->filled('tanggal')) {
            $query->whereDate($dateColumn, $request->tanggal);
        }

        if ($request->filled('tanggal_mulai')) {
            $query->whereDate($dateColumn, '>=', $request->tanggal_mulai);
        }

        if ($request->filled('tanggal_selesai')) {
            $query->whereDate($dateColumn, '<=', $request->tanggal_selesai);
        }

        $registrasiIds = $query
            ->orderBy('id')
            ->limit(100)
            ->pluck('id');

        foreach ($registrasiIds as $registrasiId) {
            try {
                DB::transaction(function () use ($registrasiId) {
                    $registrasi = RegistrasiKunjungan::query()
                        ->with([
                            'pasien',
                            'tasks',
                            'treatmentDetails',
                            'penjualanDetails',
                        ])
                        ->active()
                        ->whereKey($registrasiId)
                        ->lockForUpdate()
                        ->first();

                    if (!$registrasi) {
                        return;
                    }

                    $invoiceExists = PembayaranInvoice::query()
                        ->where('registrasi_id', $registrasi->id)
                        ->where(function ($q) {
                            $q->whereNull('is_delete')->orWhere('is_delete', 0);
                        })
                        ->whereIn('status', [
                            PembayaranInvoice::STATUS_MENUNGGU,
                            PembayaranInvoice::STATUS_PROSES,
                            PembayaranInvoice::STATUS_LUNAS,
                        ])
                        ->lockForUpdate()
                        ->exists();

                    if ($invoiceExists) {
                        return;
                    }

                    $this->generateInvoiceFromRegistrasi($registrasi, true);
                }, 3);
            } catch (Throwable $e) {
                Log::warning('Gagal sync pending invoice pada list pembayaran', [
                    'registrasi_id' => $registrasiId,
                    'error' => $e->getMessage(),
                ]);
            }
        }
    }

    protected function generateInvoiceFromRegistrasi(RegistrasiKunjungan $registrasi, bool $force = false): PembayaranInvoice
    {
        $existing = PembayaranInvoice::query()
            ->where('registrasi_id', $registrasi->id)
            ->where(function ($q) {
                $q->whereNull('is_delete')->orWhere('is_delete', 0);
            })
            ->lockForUpdate()
            ->first();

        if ($existing && (int) $existing->status === PembayaranInvoice::STATUS_LUNAS) {
            return $existing;
        }

        if ($existing && !$force) {
            return $existing;
        }

        if (!$existing) {
            $existing = new PembayaranInvoice();
        }

        $jenisTransaksi = (int) ($existing->jenis_transaksi ?? 0);
        $noInvoice = $existing->no_invoice;

        if (!$this->isLegacyInvoiceNumber($noInvoice)) {
            $noInvoice = $this->generateInvoiceNumber($registrasi, $jenisTransaksi, (int) $existing->id);
        } else {
            $noInvoice = $this->formatInvoiceNumberWithTransactionSuffix($noInvoice, $jenisTransaksi);
        }

        $tanggalInvoice = $registrasi->tanggal_kunjungan ?? $registrasi->tanggal ?? Carbon::today()->toDateString();
        $task = $registrasi->tasks
            ? $registrasi->tasks->firstWhere('task_type', RegistrasiTask::TYPE_PEMBAYARAN)
            : null;

        $existing->fill($this->onlyExistingColumns('pembayaran_invoice', [
            'registrasi_id' => $registrasi->id,
            'task_id' => $task?->id,
            'kode_registrasi' => $registrasi->kode_registrasi ?? null,
            'no_invoice' => $noInvoice,
            'tanggal_invoice' => $tanggalInvoice,
            'toko_id' => $registrasi->toko_id,
            'pasien_id' => $registrasi->pasien_id,
            'dokter_id' => $registrasi->dokter_awal_id ?? null,
            'jenis_transaksi' => 0,
            'is_premier' => (int) ($existing->is_premier ?? 0),
            'subtotal_obat' => 0,
            'subtotal_produk' => (float) ($registrasi->total_penjualan ?? 0),
            'subtotal_treatment' => (float) ($registrasi->total_treatment ?? 0),
            'subtotal_konsultasi' => (float) ($registrasi->total_konsultasi ?? 0),
            'subtotal' => (float) ($registrasi->grand_total ?? 0),
            'diskon_subtotal' => 0,
            'diskon_subtotal_amount' => 0,
            'diskon_promo' => 0,
            'total_promo' => 0,
            'grand_total' => (float) ($registrasi->grand_total ?? 0),
            'total_bayar' => 0,
            'sisa_tagihan' => (float) ($registrasi->grand_total ?? 0),
            'status' => PembayaranInvoice::STATUS_MENUNGGU,
            'is_delete' => 0,
            'created_by' => $existing->exists ? ($existing->created_by ?? $this->username()) : $this->username(),
            'updated_by' => $this->username(),
            'created_at' => $existing->exists ? ($existing->created_at ?? now()) : now(),
            'updated_at' => now(),
        ]));

        $existing->save();

        $this->syncInvoiceItemsFromRegistrasi($existing, $registrasi);
        $this->refreshInvoiceTotalsFromItems($existing);

        return $existing->fresh(['items']);
    }

    protected function syncInvoiceItemsFromRequest(Request $request, PembayaranInvoice $invoice): void
    {
        $this->invoiceItemSyncService->syncInvoiceItemsFromRequest($request, $invoice);
    }

    protected function syncInvoiceItemsFromRegistrasi(PembayaranInvoice $invoice, RegistrasiKunjungan $registrasi): void
    {
        $this->invoiceItemSyncService->syncInvoiceItemsFromRegistrasi($invoice, $registrasi);
    }

    protected function refreshInvoiceTotalsFromItems(PembayaranInvoice $invoice): void
    {
        $this->invoiceItemSyncService->refreshInvoiceTotalsFromItems($invoice);
    }

    protected function normalizeSubtotalDiscountType($value): int
    {
        $text = strtolower(trim((string) $value));

        if ($text === '1' || $text === '%' || $text === 'percent' || $text === 'persen') {
            return 1;
        }

        if ($text === '2' || $text === 'rp' || $text === 'rupiah' || $text === 'nominal') {
            return 2;
        }

        return 0;
    }

    protected function getSubtotalDiscountProrationBase(PembayaranInvoice $invoice): float
    {
        if (!Schema::hasTable('pembayaran_invoice_item')) {
            return 0.0;
        }

        return (float) DB::table('pembayaran_invoice_item')
            ->where('pembayaran_id', $invoice->id)
            ->whereIn('item_type', [2, 3])
            ->where(function ($q) {
                $q->whereNull('is_delete')->orWhere('is_delete', 0);
            })
            ->sum('subtotal');
    }

    protected function prorateSubtotalDiscountToItems(PembayaranInvoice $invoice, float $diskonSubtotal): void
    {
        if (!Schema::hasTable('pembayaran_invoice_item')) {
            return;
        }

        $hasProrataAmount = Schema::hasColumn('pembayaran_invoice_item', 'diskon_subtotal_amount');
        $hasBeforeColumn = Schema::hasColumn('pembayaran_invoice_item', 'subtotal_before_diskon_subtotal');
        $hasAfterColumn = Schema::hasColumn('pembayaran_invoice_item', 'subtotal_after_diskon_subtotal');

        if (!$hasProrataAmount && !$hasBeforeColumn && !$hasAfterColumn) {
            return;
        }

        $items = DB::table('pembayaran_invoice_item')
            ->where('pembayaran_id', $invoice->id)
            ->whereIn('item_type', [2, 3])
            ->where(function ($q) {
                $q->whereNull('is_delete')->orWhere('is_delete', 0);
            })
            ->orderBy('id')
            ->lockForUpdate()
            ->get(['id', 'subtotal']);

        $baseSubtotal = (float) $items->sum(fn ($item) => (float) ($item->subtotal ?? 0));
        $diskonSubtotal = min(max($diskonSubtotal, 0), max($baseSubtotal, 0));

        if ($items->isEmpty() || $baseSubtotal <= 0 || $diskonSubtotal <= 0) {
            foreach ($items as $item) {
                $subtotal = (float) ($item->subtotal ?? 0);
                $payload = [
                    'updated_by' => $this->username(),
                    'updated_at' => now(),
                ];

                if ($hasProrataAmount) {
                    $payload['diskon_subtotal_amount'] = 0;
                }
                if ($hasBeforeColumn) {
                    $payload['subtotal_before_diskon_subtotal'] = $subtotal;
                }
                if ($hasAfterColumn) {
                    $payload['subtotal_after_diskon_subtotal'] = $subtotal;
                }

                DB::table('pembayaran_invoice_item')
                    ->where('id', $item->id)
                    ->update($this->onlyExistingColumns('pembayaran_invoice_item', $payload));
            }

            return;
        }

        $allocated = 0.0;
        $lastIndex = $items->count() - 1;

        foreach ($items->values() as $index => $item) {
            $subtotal = (float) ($item->subtotal ?? 0);

            if ($index === $lastIndex) {
                $amount = round($diskonSubtotal - $allocated, 2);
            } else {
                $amount = round(($subtotal / $baseSubtotal) * $diskonSubtotal, 2);
                $allocated += $amount;
            }

            $amount = min(max($amount, 0), $subtotal);
            $afterSubtotal = max($subtotal - $amount, 0);

            $payload = [
                'updated_by' => $this->username(),
                'updated_at' => now(),
            ];

            if ($hasProrataAmount) {
                $payload['diskon_subtotal_amount'] = $amount;
            }
            if ($hasBeforeColumn) {
                $payload['subtotal_before_diskon_subtotal'] = $subtotal;
            }
            if ($hasAfterColumn) {
                $payload['subtotal_after_diskon_subtotal'] = $afterSubtotal;
            }

            DB::table('pembayaran_invoice_item')
                ->where('id', $item->id)
                ->update($this->onlyExistingColumns('pembayaran_invoice_item', $payload));
        }
    }

    protected function calculateInvoiceItemDiscountAmount(float $gross, int $diskonTipe, float $diskonNilai): float
    {
        if ($gross <= 0 || $diskonNilai <= 0) {
            return 0;
        }

        if ($diskonTipe === 1) {
            return min(round(($gross * $diskonNilai) / 100, 2), $gross);
        }

        if ($diskonTipe === 2) {
            return min($diskonNilai, $gross);
        }

        return 0;
    }

    protected function buildInstruksiPemakaian(?string $frekuensi, ?string $waktuPakai): ?string
    {
        $parts = array_values(array_filter([
            trim((string) $frekuensi),
            trim((string) $waktuPakai),
        ]));

        return empty($parts) ? null : implode(' - ', $parts);
    }

    protected function ensureLegacyInvoiceNumber(
        PembayaranInvoice $invoice,
        RegistrasiKunjungan $registrasi,
        ?int $jenisTransaksi = null
    ): void {
        $jenisTransaksi = $jenisTransaksi ?? (int) ($invoice->jenis_transaksi ?? 0);

        $currentNumber = trim((string) ($invoice->no_invoice ?? ''));
        $currentBase = $this->normalizeInvoiceNumberWithoutTransactionSuffix($currentNumber);
        $expectedSuffix = $this->resolveInvoiceTransactionSuffix($jenisTransaksi);

        $baseNumber = $this->resolveInvoiceBaseNumber($invoice, $registrasi);

        if ($baseNumber === '') {
            $baseNumber = $this->generateInvoiceBaseNumber($registrasi, (int) $invoice->id);
        }

        $newNumber = $baseNumber . '-' . $expectedSuffix;

        if ($newNumber === $currentNumber) {
            $payload = [
                'invoice_base_no' => $baseNumber,
                'invoice_suffix' => $expectedSuffix,
                'updated_by' => $this->username(),
                'updated_at' => now(),
            ];

            $invoice->forceFill(
                $this->onlyExistingColumns('pembayaran_invoice', $payload)
            )->save();

            $invoice->refresh();
            return;
        }

        if ($this->invoiceNumberExists($newNumber, (int) $invoice->id)) {
            $baseNumber = $this->generateInvoiceBaseNumber($registrasi, (int) $invoice->id);
            $newNumber = $baseNumber . '-' . $expectedSuffix;
        }

        $payload = [
            'no_invoice' => $newNumber,
            'invoice_base_no' => $baseNumber,
            'invoice_suffix' => $expectedSuffix,
            'updated_by' => $this->username(),
            'updated_at' => now(),
        ];

        $invoice->forceFill(
            $this->onlyExistingColumns('pembayaran_invoice', $payload)
        )->save();

        $invoice->refresh();
    }

    protected function resolveInvoiceBaseNumber(
        PembayaranInvoice $invoice,
        RegistrasiKunjungan $registrasi
    ): string {
        $existingBase = trim((string) ($invoice->invoice_base_no ?? ''));

        if ($existingBase !== '') {
            return $this->normalizeInvoiceNumberWithoutTransactionSuffix($existingBase);
        }

        $currentNumber = trim((string) ($invoice->no_invoice ?? ''));
        $currentBase = $this->normalizeInvoiceNumberWithoutTransactionSuffix($currentNumber);

        if ($currentBase !== '' && $this->isLegacyInvoiceNumber($currentBase)) {
            return $currentBase;
        }

        $siblingInvoice = PembayaranInvoice::query()
            ->where('registrasi_id', $registrasi->id)
            ->where('id', '<>', $invoice->id)
            ->where(function ($q) {
                $q->whereNull('is_delete')->orWhere('is_delete', 0);
            })
            ->whereNotNull('no_invoice')
            ->orderBy('id')
            ->lockForUpdate()
            ->first();

        if ($siblingInvoice) {
            $siblingBase = trim((string) ($siblingInvoice->invoice_base_no ?? ''));

            if ($siblingBase !== '') {
                return $this->normalizeInvoiceNumberWithoutTransactionSuffix($siblingBase);
            }

            $siblingNumber = trim((string) ($siblingInvoice->no_invoice ?? ''));

            if ($siblingNumber !== '') {
                return $this->normalizeInvoiceNumberWithoutTransactionSuffix($siblingNumber);
            }
        }

        return '';
    }

    protected function generateInvoiceNumber(
        RegistrasiKunjungan $registrasi,
        int $jenisTransaksi = 0,
        ?int $ignoreInvoiceId = null
    ): string {
        $baseNumber = $this->generateInvoiceBaseNumber($registrasi, $ignoreInvoiceId);

        return $baseNumber . '-' . $this->resolveInvoiceTransactionSuffix($jenisTransaksi);
    }

    protected function generateInvoiceBaseNumber(
        RegistrasiKunjungan $registrasi,
        ?int $ignoreInvoiceId = null
    ): string {
        $invoiceDate = $this->resolveInvoiceDate($registrasi);
        $tokoId = (int) ($registrasi->toko_id ?? 0);
        $tokoCode = $this->resolveLegacyInvoiceTokoCode($tokoId);

        do {
            $sequence = $this->nextLegacyInvoiceSequence($tokoId, $invoiceDate);
            $baseNumber = $this->formatLegacyInvoiceNumber($tokoCode, $invoiceDate, $sequence);
        } while ($this->invoiceBaseNumberExists($baseNumber, $ignoreInvoiceId));

        return $baseNumber;
    }

    protected function resolveInvoiceDate(RegistrasiKunjungan $registrasi): Carbon
    {
        return Carbon::parse(
            $registrasi->tanggal_kunjungan
                ?? $registrasi->tanggal
                ?? $registrasi->registered_at
                ?? now()
        );
    }

    protected function isLegacyInvoiceNumber(?string $invoiceNumber): bool
    {
        $baseNumber = $this->normalizeInvoiceNumberWithoutTransactionSuffix($invoiceNumber);

        if ($baseNumber === '') {
            return false;
        }

        return !str_starts_with(strtoupper($baseNumber), 'INV-');
    }

    protected function formatInvoiceNumberWithTransactionSuffix(?string $invoiceNumber, int $jenisTransaksi): string
    {
        $baseNumber = $this->normalizeInvoiceNumberWithoutTransactionSuffix($invoiceNumber);

        if ($baseNumber === '') {
            return '';
        }

        return $baseNumber . '-' . $this->resolveInvoiceTransactionSuffix($jenisTransaksi);
    }

    protected function resolveInvoiceTransactionSuffix(int $jenisTransaksi): string
    {
        return match ($jenisTransaksi) {
            4 => 'D',
            1 => 'F',
            2 => 'E',
            3 => 'O',
            default => 'U',
        };
    }

    protected function invoiceNumberExists(string $invoiceNumber, ?int $ignoreInvoiceId = null): bool
    {
        $query = PembayaranInvoice::query()
            ->where('no_invoice', $invoiceNumber);

        if ($ignoreInvoiceId) {
            $query->where('id', '<>', $ignoreInvoiceId);
        }

        return $query->exists();
    }

    protected function invoiceBaseNumberExists(string $baseNumber, ?int $ignoreInvoiceId = null): bool
    {
        $baseNumber = $this->normalizeInvoiceNumberWithoutTransactionSuffix($baseNumber);

        if ($baseNumber === '') {
            return false;
        }

        $query = PembayaranInvoice::query()
            ->where(function ($q) use ($baseNumber) {
                $q->where('invoice_base_no', $baseNumber)
                    ->orWhere('no_invoice', $baseNumber)
                    ->orWhere('no_invoice', $baseNumber . '-U')
                    ->orWhere('no_invoice', $baseNumber . '-D')
                    ->orWhere('no_invoice', $baseNumber . '-F')
                    ->orWhere('no_invoice', $baseNumber . '-E')
                    ->orWhere('no_invoice', $baseNumber . '-O');
            });

        if ($ignoreInvoiceId) {
            $query->where('id', '<>', $ignoreInvoiceId);
        }

        return $query->exists();
    }

    protected function normalizeInvoiceNumberWithoutTransactionSuffix(?string $invoiceNumber): string
    {
        $invoiceNumber = trim((string) $invoiceNumber);

        if ($invoiceNumber === '') {
            return '';
        }

        return preg_replace('/-[A-Z]$/', '', strtoupper($invoiceNumber)) ?: $invoiceNumber;
    }

    protected function resolveLegacyInvoiceTokoCode(int $tokoId): string
    {
        if ($tokoId <= 0 || !Schema::hasTable('master_toko')) {
            return 'TOKO';
        }

        $toko = DB::table('master_toko')
            ->where('id', $tokoId)
            ->where(function ($q) {
                $q->whereNull('is_delete')->orWhere('is_delete', 0);
            })
            ->lockForUpdate()
            ->first();

        $code = $toko->kode_toko
            ?? $toko->kode
            ?? $toko->nama_toko
            ?? ('T' . $tokoId);

        $code = strtoupper(preg_replace('/[^A-Z0-9]/', '', (string) $code));

        return $code !== '' ? $code : ('T' . $tokoId);
    }

    protected function nextLegacyInvoiceSequence(int $tokoId, Carbon $invoiceDate): int
    {
        $date = $invoiceDate->toDateString();

        if (!Schema::hasTable('pembayaran_invoice_sequence')) {
            return $this->nextLegacyInvoiceSequenceFromInvoiceTable($tokoId, $invoiceDate);
        }

        $sequenceRow = PembayaranInvoiceSequence::query()
            ->where('toko_id', $tokoId)
            ->whereDate('tanggal', $date)
            ->lockForUpdate()
            ->first();

        if (!$sequenceRow) {
            $initialSequence = $this->currentLegacyInvoiceSequenceFromInvoiceTable($tokoId, $invoiceDate);

            try {
                PembayaranInvoiceSequence::query()->create([
                    'toko_id' => $tokoId,
                    'tanggal' => $date,
                    'last_sequence' => $initialSequence,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            } catch (Throwable $e) {
                // Request paralel bisa membuat row sequence lebih dulu.
                // Ambil ulang dengan lock.
            }

            $sequenceRow = PembayaranInvoiceSequence::query()
                ->where('toko_id', $tokoId)
                ->whereDate('tanggal', $date)
                ->lockForUpdate()
                ->first();
        }

        if (!$sequenceRow) {
            return $this->nextLegacyInvoiceSequenceFromInvoiceTable($tokoId, $invoiceDate);
        }

        $sequenceRow->last_sequence = ((int) $sequenceRow->last_sequence) + 1;
        $sequenceRow->updated_at = now();
        $sequenceRow->save();

        return (int) $sequenceRow->last_sequence;
    }

    protected function currentLegacyInvoiceSequenceFromInvoiceTable(int $tokoId, Carbon $invoiceDate): int
    {
        $prefix = $this->legacyInvoicePrefix(
            $this->resolveLegacyInvoiceTokoCode($tokoId),
            $invoiceDate
        );

        $lastInvoice = PembayaranInvoice::query()
            ->where('toko_id', $tokoId)
            ->whereDate('tanggal_invoice', $invoiceDate->toDateString())
            ->where('no_invoice', 'like', $prefix . '%')
            ->where(function ($q) {
                $q->whereNull('is_delete')->orWhere('is_delete', 0);
            })
            ->lockForUpdate()
            ->orderByDesc('no_invoice')
            ->value('no_invoice');

        if (!$lastInvoice) {
            return 0;
        }

        return $this->extractLegacyInvoiceSequence($lastInvoice);
    }

    protected function nextLegacyInvoiceSequenceFromInvoiceTable(int $tokoId, Carbon $invoiceDate): int
    {
        $prefix = $this->legacyInvoicePrefix(
            $this->resolveLegacyInvoiceTokoCode($tokoId),
            $invoiceDate
        );

        $lastInvoice = PembayaranInvoice::query()
            ->where('toko_id', $tokoId)
            ->whereDate('tanggal_invoice', $invoiceDate->toDateString())
            ->where('no_invoice', 'like', $prefix . '%')
            ->where(function ($q) {
                $q->whereNull('is_delete')->orWhere('is_delete', 0);
            })
            ->lockForUpdate()
            ->orderByDesc('no_invoice')
            ->value('no_invoice');

        if (!$lastInvoice) {
            return 1;
        }

        return $this->extractLegacyInvoiceSequence($lastInvoice) + 1;
    }

    protected function extractLegacyInvoiceSequence(?string $invoiceNumber): int
    {
        $baseNumber = $this->normalizeInvoiceNumberWithoutTransactionSuffix($invoiceNumber);

        if ($baseNumber === '') {
            return 0;
        }

        preg_match('/(\d{5})$/', $baseNumber, $matches);

        return isset($matches[1]) ? (int) $matches[1] : 0;
    }

    protected function formatLegacyInvoiceNumber(string $tokoCode, Carbon $invoiceDate, int $sequence): string
    {
        return $this->legacyInvoicePrefix($tokoCode, $invoiceDate)
            . str_pad((string) $sequence, 5, '0', STR_PAD_LEFT);
    }

    protected function legacyInvoicePrefix(string $tokoCode, Carbon $invoiceDate): string
    {
        return $tokoCode . $invoiceDate->format('Ymd');
    }

    protected function resolvePasienUpdaterId(): ?int
    {
        $user = null;

        try {
            $user = auth()->user() ?: auth('api')->user();
        } catch (\Throwable $e) {
            $user = null;
        }

        if (!$user) {
            return null;
        }

        foreach (['id', 'user_id', 'master_user_id', 'karyawan_id'] as $field) {
            $value = $user->{$field} ?? null;

            if (is_numeric($value) && (int) $value > 0) {
                return (int) $value;
            }
        }

        return null;
    }

    protected function recalculateInvoiceTotals(PembayaranInvoice $invoice): void
    {
        $items = $invoice->items()
            ->where(function ($q) {
                $q->whereNull('is_delete')->orWhere('is_delete', 0);
            })
            ->get();

        $subtotalProduk = 0;
        $subtotalTreatment = 0;
        $subtotalKonsultasi = 0;

        foreach ($items as $item) {
            $amount = (float) ($item->subtotal ?? $item->total ?? 0);
            $type = $item->item_type ?? $item->jenis_item ?? null;

            if (is_numeric($type)) {
                if ((int) $type === 1) {
                    $subtotalKonsultasi += $amount;
                } elseif ((int) $type === 3) {
                    $subtotalProduk += $amount;
                } else {
                    $subtotalTreatment += $amount;
                }

                continue;
            }

            $type = strtolower((string) $type);
            if (in_array($type, ['produk', 'obat', 'penjualan'], true)) {
                $subtotalProduk += $amount;
            } elseif ($type === 'konsultasi') {
                $subtotalKonsultasi += $amount;
            } else {
                $subtotalTreatment += $amount;
            }
        }

        $subtotal = $subtotalProduk + $subtotalTreatment + $subtotalKonsultasi;
        $diskonSubtotal = (float) ($invoice->diskon_subtotal ?? $invoice->diskon_subtotal_amount ?? 0);
        $diskonPromo = (float) ($invoice->diskon_promo ?? $invoice->total_promo ?? 0);
        $grandTotal = max($subtotal - $diskonSubtotal - $diskonPromo, 0);
        $totalBayar = (float) ($invoice->total_bayar ?? 0);

        $invoice->update($this->onlyExistingColumns('pembayaran_invoice', [
            'subtotal_obat' => $subtotalProduk,
            'subtotal_produk' => $subtotalProduk,
            'subtotal_treatment' => $subtotalTreatment,
            'subtotal_konsultasi' => $subtotalKonsultasi,
            'subtotal' => $subtotal,
            'grand_total' => $grandTotal,
            'sisa_tagihan' => max($grandTotal - $totalBayar, 0),
            'updated_by' => $this->username(),
            'updated_at' => now(),
        ]));
    }

    protected function invoiceSubtotalProduk(PembayaranInvoice $invoice): float
    {
        return (float) ($invoice->subtotal_obat ?? $invoice->subtotal_produk ?? 0);
    }

    protected function resolveKonsultasiMeta($registrasi): array
    {
        if (!$registrasi) {
            return [
                'channel' => 0,
                'channel_key' => 'tanpa_konsultasi',
                'channel_label' => 'Tanpa Konsultasi',
                'source_code' => null,
                'source_name' => null,
                'jenis_label' => 'Tanpa Konsultasi',
            ];
        }

        $channel = (int) ($registrasi->channel_konsultasi ?? 0);

        $sourceCode = trim((string) ($registrasi->konsultasi_source_code ?? ''));
        $sourceName = trim((string) ($registrasi->konsultasi_source_name ?? ''));

        if ($sourceCode === '' && $channel > 0) {
            $sourceCode = $channel === 2
                ? 'KONSULTASI_ONLINE'
                : 'KONSULTASI_OFFLINE';
        }

        if ($sourceName === '') {
            $sourceName = $this->mapKonsultasiSourceName($sourceCode);
        }

        $channelKey = $this->resolveKonsultasiChannelKey($channel);
        $channelLabel = $this->resolveKonsultasiChannelLabel($channel);

        return [
            'channel' => $channel,
            'channel_key' => $channelKey,
            'channel_label' => $channelLabel,
            'source_code' => $sourceCode !== '' ? $sourceCode : null,
            'source_name' => $sourceName !== '' ? $sourceName : null,
            'jenis_label' => $sourceName !== '' ? $sourceName : $channelLabel,
        ];
    }

    protected function resolveKonsultasiChannelKey(int $channel): string
    {
        return match ($channel) {
            1 => 'offline',
            2 => 'online',
            default => 'tanpa_konsultasi',
        };
    }

    protected function resolveKonsultasiChannelLabel(int $channel): string
    {
        return match ($channel) {
            1 => 'Konsultasi Offline',
            2 => 'Konsultasi Online',
            default => 'Tanpa Konsultasi',
        };
    }

    protected function mapKonsultasiSourceName(?string $sourceCode): string
    {
        $sourceCode = strtoupper(trim((string) $sourceCode));

        return match ($sourceCode) {
            'KONSULTASI_OFFLINE' => 'Konsultasi Dokter',
            'KONSULTASI_ONLINE' => 'Konsultasi Online',
            'KONSULTASI_SPPG' => 'Konsultasi SPPG',
            'KONSULTASI_SPKK' => 'Konsultasi SPKK',
            default => '',
        };
    }

    protected function onlyExistingColumns(string $table, array $data): array
    {
        if (!isset($this->existingColumnCache[$table])) {
            try {
                $this->existingColumnCache[$table] = array_flip(Schema::getColumnListing($table));
            } catch (Throwable $e) {
                $this->existingColumnCache[$table] = [];
            }
        }

        if (empty($this->existingColumnCache[$table])) {
            return [];
        }

        return array_intersect_key($data, $this->existingColumnCache[$table]);
    }

    protected function username(): string
    {
        if ($this->cachedUsername !== null) {
            return $this->cachedUsername;
        }

        $this->cachedUsername = auth()->user()->username
            ?? auth()->user()->name
            ?? 'system';

        return $this->cachedUsername;
    }

    private function buildInvoiceQrDataUri(PembayaranInvoice $invoice): ?string
    {
        $qrTargetUrl = $this->buildInvoiceQrTargetUrl($invoice);

        if (!$qrTargetUrl) {
            return null;
        }

        $writer = new SvgWriter();

        $qrCode = new QrCode(
            data: $qrTargetUrl,
            encoding: new Encoding('UTF-8'),
            errorCorrectionLevel: ErrorCorrectionLevel::Medium,
            size: 120,
            margin: 2,
            roundBlockSizeMode: RoundBlockSizeMode::Margin
        );

        $result = $writer->write($qrCode);

        return $result->getDataUri();
    }

    private function buildInvoiceQrTargetUrl(PembayaranInvoice $invoice): ?string
    {
        $baseUrl = rtrim(
            trim((string) env('SIPUAS_REVIEW_URL', 'https://sipuas.msglowclinic.id')),
            '/'
        );

        $tokoId = (int) (
            $invoice->toko_id
            ?? $invoice->registrasi?->toko_id
            ?? 0
        );

        $pasien = $invoice->registrasi?->pasien;

        $sipuasPasienId = $pasien->new_id
            ?? $pasien->pasien_new_id
            ?? $pasien->id_lama
            ?? $invoice->pasien_new_id
            ?? $invoice->pasien_id
            ?? null;

        $sipuasPasienId = trim((string) $sipuasPasienId);

        if ($tokoId <= 0 || $sipuasPasienId === '') {
            return null;
        }

        return $baseUrl . '/' . $tokoId . '/' . rawurlencode($sipuasPasienId);
    }

    private function startPaymentFinishTrace(Request $request, $id): array
    {
        return [
            'trace_id' => now()->format('YmdHis') . '-' . bin2hex(random_bytes(4)),
            'invoice_param_id' => $id,
            'started_at' => microtime(true),
            'last_at' => microtime(true),
            'request_summary' => [
                'jenis_transaksi' => $request->input('jenis_transaksi'),
                'is_premier' => $request->input('is_premier'),
                'grand_total' => $request->input('grand_total'),
                'metode_count' => is_array($request->input('metode')) ? count($request->input('metode')) : 0,
                'penjualan_count' => is_array($request->input('penjualan_items')) ? count($request->input('penjualan_items')) : 0,
                'treatment_count' => is_array($request->input('treatment_items')) ? count($request->input('treatment_items')) : 0,
                'promo_count' => is_array($request->input('promos')) ? count($request->input('promos')) : 0,
                'promo_id_count' => is_array($request->input('promo_ids')) ? count($request->input('promo_ids')) : 0,
                'deposit_item_count' => is_array($request->input('deposit_items')) ? count($request->input('deposit_items')) : 0,
                'deposit_treatment_item_count' => is_array($request->input('deposit_treatment_items')) ? count($request->input('deposit_treatment_items')) : 0,
                'update_pasien_phone' => $request->input('update_pasien_phone'),
            ],
        ];
    }

    private function markPaymentFinishTrace(array &$trace, string $label, array $context = []): void
    {
        $now = microtime(true);
        $startedAt = (float) ($trace['started_at'] ?? $now);
        $lastAt = (float) ($trace['last_at'] ?? $startedAt);

        $trace['last_at'] = $now;

        logger()->info('[PAYMENT_FINISH_TRACE] ' . $label, array_merge([
            'trace_id' => $trace['trace_id'] ?? null,
            'invoice_param_id' => $trace['invoice_param_id'] ?? null,
            'step_ms' => round(($now - $lastAt) * 1000, 2),
            'total_ms' => round(($now - $startedAt) * 1000, 2),
            'memory_mb' => round(memory_get_usage(true) / 1048576, 2),
        ], $context));
    }


    // Ganti method finish() lama dengan versi ini.

    public function finish(Request $request, $id)
    {
        $trace = $this->startPaymentFinishTrace($request, $id);
        $this->markPaymentFinishTrace($trace, 'request.received', $trace['request_summary'] ?? []);

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
            'is_premier' => 'nullable|boolean',
            'referensi_dokter_id' => 'nullable|integer',
            'deposit_expired_option_id' => 'nullable|integer',
            'deposit_expired_at' => 'nullable|date',
            'deposit_item_ids' => 'nullable|array',
            'deposit_item_ids.*' => 'nullable|integer',
            'deposit_treatment_item_ids' => 'nullable|array',
            'deposit_treatment_item_ids.*' => 'nullable|integer',
            'deposit_items' => 'nullable|array',
            'deposit_items.*.item_id' => 'nullable|integer',
            'deposit_items.*.pembayaran_item_id' => 'nullable|integer',
            'deposit_items.*.invoice_item_id' => 'nullable|integer',
            'deposit_items.*.qty' => 'nullable|numeric|min:0',
            'deposit_items.*.qty_deposit' => 'nullable|numeric|min:0',
            'deposit_treatment_items' => 'nullable|array',
            'deposit_treatment_items.*.item_id' => 'nullable|integer',
            'deposit_treatment_items.*.pembayaran_item_id' => 'nullable|integer',
            'deposit_treatment_items.*.invoice_item_id' => 'nullable|integer',
            'deposit_treatment_items.*.qty' => 'nullable|numeric|min:0',
            'deposit_treatment_items.*.qty_deposit' => 'nullable|numeric|min:0',
            'deposit_item_quantities' => 'nullable|array',
            'update_pasien_phone' => 'nullable|boolean',
            'pasien_no_hp_update' => 'nullable|string|max:30',
            'pasien_no_wa_update' => 'nullable|string|max:30',
            'pasien_no_telp_update' => 'nullable|string|max:30',
            'sumber_informasi_id' => 'nullable|integer',
            'sumber_kedatangan' => 'nullable|string|max:100',
            'promo_ids' => 'nullable|array',
            'promo_ids.*' => 'nullable|integer',
            'promos' => 'nullable|array',
            'selected_promos' => 'nullable|array',
            'applied_promos' => 'nullable|array',
            'promo_code' => 'nullable|string|max:100',
            'diskon_subtotal_tipe' => 'nullable',
            'diskon_subtotal_nilai' => 'nullable|numeric|min:0',
        ]);

        $this->markPaymentFinishTrace($trace, 'validator.finished');

        if ($validator->fails()) {
            $this->markPaymentFinishTrace($trace, 'validator.failed', [
                'errors' => $validator->errors()->keys(),
            ]);

            return response()->json([
                'status' => false,
                'message' => 'Validasi gagal',
                'errors' => $validator->errors(),
            ], 422);
        }

        try {
            $result = DB::transaction(function () use ($request, $id, &$trace) {
                $this->markPaymentFinishTrace($trace, 'transaction.started');

                $invoice = $this->resolveInvoiceForUpdate($id);
                $this->markPaymentFinishTrace($trace, 'resolveInvoiceForUpdate.finished', [
                    'invoice_id' => $invoice?->id,
                    'invoice_status' => $invoice?->status,
                    'registrasi_id' => $invoice?->registrasi_id,
                ]);

                if (!$invoice) {
                    throw ValidationException::withMessages([
                        'invoice' => 'Invoice tidak ditemukan.',
                    ]);
                }

                if ((int) $invoice->status === PembayaranInvoice::STATUS_LUNAS) {
                    throw ValidationException::withMessages([
                        'invoice' => 'Invoice sudah lunas.',
                    ]);
                }

                $registrasi = RegistrasiKunjungan::query()
                    ->with(['tasks'])
                    ->whereKey($invoice->registrasi_id)
                    ->where(function ($q) {
                        $q->whereNull('is_delete')->orWhere('is_delete', 0);
                    })
                    ->lockForUpdate()
                    ->first();

                $this->markPaymentFinishTrace($trace, 'registrasi.locked', [
                    'registrasi_id' => $registrasi?->id,
                    'task_count' => $registrasi?->tasks?->count(),
                ]);

                if (!$registrasi) {
                    throw ValidationException::withMessages([
                        'registrasi' => 'Data registrasi invoice tidak ditemukan.',
                    ]);
                }

                $jenisTransaksi = (int) $request->input('jenis_transaksi', $invoice->jenis_transaksi ?? 0);
                $isPremier = $this->resolveIsPremierFromRequest($request, $invoice);
                $depositExpiredAt = $this->resolveDepositExpiredAt($request);

                $invoice->forceFill($this->onlyExistingColumns('pembayaran_invoice', [
                    'is_premier' => $isPremier,
                    'updated_by' => $this->username(),
                    'updated_at' => now(),
                ]))->save();

                $this->markPaymentFinishTrace($trace, 'resolvePaymentOptions.finished', [
                    'jenis_transaksi' => $jenisTransaksi,
                    'is_premier' => $isPremier,
                    'deposit_expired_at' => $depositExpiredAt,
                ]);

                $this->ensureLegacyInvoiceNumber($invoice, $registrasi, $jenisTransaksi);
                $this->markPaymentFinishTrace($trace, 'ensureLegacyInvoiceNumber.finished', [
                    'invoice_id' => $invoice->id,
                    'no_invoice' => $invoice->no_invoice,
                ]);

                $registrasi->loadMissing(['treatmentDetails', 'penjualanDetails']);
                $this->markPaymentFinishTrace($trace, 'registrasi.loadMissing.billingDetails.finished', [
                    'treatment_detail_count' => $registrasi->treatmentDetails?->count(),
                    'penjualan_detail_count' => $registrasi->penjualanDetails?->count(),
                ]);

                $this->syncInvoiceItemsFromRegistrasi($invoice, $registrasi);
                $this->markPaymentFinishTrace($trace, 'syncInvoiceItemsFromRegistrasi.finished');

                $this->syncInvoiceItemsFromRequest($request, $invoice);
                $this->markPaymentFinishTrace($trace, 'syncInvoiceItemsFromRequest.finished');

                $this->refreshInvoiceTotalsFromItems($invoice);
                $this->markPaymentFinishTrace($trace, 'refreshInvoiceTotalsFromItems.1.finished');

                $invoice = PembayaranInvoice::query()
                    ->whereKey($invoice->id)
                    ->lockForUpdate()
                    ->firstOrFail();
                $invoice->load([
                    'items',
                    'metode',
                    'promos',
                    'depositClaims',
                ]);
                $this->markPaymentFinishTrace($trace, 'invoice.reload.1.finished', [
                    'invoice_id' => $invoice->id,
                    'item_count' => $invoice->items?->count(),
                    'metode_count' => $invoice->metode?->count(),
                    'promo_count' => $invoice->promos?->count(),
                    'deposit_claim_count' => $invoice->depositClaims?->count(),
                    'grand_total' => (float) ($invoice->grand_total ?? 0),
                ]);

                $this->updatePatientPhoneFromRequest($request, $invoice);
                $this->markPaymentFinishTrace($trace, 'updatePatientPhoneFromRequest.finished');

                $this->validateJenisTransaksiKhusus($request, $invoice, $jenisTransaksi, $depositExpiredAt);
                $this->markPaymentFinishTrace($trace, 'validateJenisTransaksiKhusus.finished');

                if ($jenisTransaksi === 4 && $this->shouldSplitDepositInvoice($request, $invoice)) {
                    $this->markPaymentFinishTrace($trace, 'finishSplitDepositInvoice.started');
                    $splitResult = $this->finishSplitDepositInvoice($request, $invoice, $registrasi, $depositExpiredAt, $trace);
                    $this->markPaymentFinishTrace($trace, 'finishSplitDepositInvoice.finished');
                    return $splitResult;
                }

                $this->syncJenisTransaksiToInvoiceItems($invoice, $jenisTransaksi);
                $this->markPaymentFinishTrace($trace, 'syncJenisTransaksiToInvoiceItems.finished');

                if ($jenisTransaksi !== 4) {
                    $this->processDepositClaimsFromInvoice($invoice, $registrasi);
                    $this->markPaymentFinishTrace($trace, 'processDepositClaimsFromInvoice.finished');
                } else {
                    $this->markPaymentFinishTrace($trace, 'processDepositClaimsFromInvoice.skipped');
                }

                $this->applySubtotalDiscountFromRequest($request, $invoice);
                $this->markPaymentFinishTrace($trace, 'applySubtotalDiscountFromRequest.finished', [
                    'diskon_subtotal_tipe' => $request->input('diskon_subtotal_tipe'),
                    'diskon_subtotal_nilai' => $request->input('diskon_subtotal_nilai'),
                ]);

                $this->refreshInvoiceTotalsFromItems($invoice);
                $this->markPaymentFinishTrace($trace, 'refreshInvoiceTotalsFromItems.2.finished');

                $invoice = PembayaranInvoice::query()
                    ->whereKey($invoice->id)
                    ->lockForUpdate()
                    ->firstOrFail();
                $invoice->load(['items', 'metode', 'promos', 'depositClaims']);
                $this->markPaymentFinishTrace($trace, 'invoice.reload.2.finished', [
                    'item_count' => $invoice->items?->count(),
                    'promo_count' => $invoice->promos?->count(),
                    'deposit_claim_count' => $invoice->depositClaims?->count(),
                    'grand_total' => (float) ($invoice->grand_total ?? 0),
                ]);

                $this->voucherFinalizerService->applySelectedPromosFromRequest(
                    $invoice,
                    $request,
                    $this->username()
                );
                $this->markPaymentFinishTrace($trace, 'voucherFinalizerService.applySelectedPromosFromRequest.finished', [
                    'request_promo_ids_count' => is_array($request->input('promo_ids')) ? count($request->input('promo_ids')) : 0,
                    'request_promos_count' => is_array($request->input('promos')) ? count($request->input('promos')) : 0,
                    'promo_code_present' => filled($request->input('promo_code')),
                ]);

                $invoice = PembayaranInvoice::query()
                    ->whereKey($invoice->id)
                    ->lockForUpdate()
                    ->firstOrFail();
                $invoice->load(['items', 'metode', 'promos', 'depositClaims']);
                $this->markPaymentFinishTrace($trace, 'invoice.reload.3.finished', [
                    'item_count' => $invoice->items?->count(),
                    'promo_count' => $invoice->promos?->count(),
                    'deposit_claim_count' => $invoice->depositClaims?->count(),
                    'grand_total' => (float) ($invoice->grand_total ?? 0),
                ]);

                $memberBeforeLedger = $this->snapshotPaymentMemberReward($invoice);
                $this->markPaymentFinishTrace($trace, 'snapshotPaymentMemberReward.finished', [
                    'member_before' => $memberBeforeLedger,
                ]);

                $this->memberPointService->applyMemberBenefitToInvoice($invoice, $this->username());
                $this->markPaymentFinishTrace($trace, 'memberPointService.applyMemberBenefitToInvoice.finished');

                $this->refreshInvoiceTotalsFromItems($invoice);
                $this->markPaymentFinishTrace($trace, 'refreshInvoiceTotalsFromItems.3.finished');

                $invoice = PembayaranInvoice::query()
                    ->whereKey($invoice->id)
                    ->lockForUpdate()
                    ->firstOrFail();
                $invoice->load([
                    'items',
                    'metode',
                    'promos',
                    'depositClaims',
                ]);
                $this->markPaymentFinishTrace($trace, 'invoice.reload.4.finished', [
                    'item_count' => $invoice->items?->count(),
                    'promo_count' => $invoice->promos?->count(),
                    'deposit_claim_count' => $invoice->depositClaims?->count(),
                    'grand_total' => (float) ($invoice->grand_total ?? 0),
                ]);

                $this->nurseTreatmentMaterialService->syncFromInvoice($invoice, $registrasi, $this->username());
                $this->markPaymentFinishTrace($trace, 'nurseTreatmentMaterialService.syncFromInvoice.finished');

                $metode = $this->normalizeMetodePayload($request, $invoice);
                $this->markPaymentFinishTrace($trace, 'normalizeMetodePayload.finished', [
                    'metode_count' => count($metode),
                ]);

                $totalBayar = collect($metode)->sum('nominal_dialokasikan');
                $grandTotal = (float) ($invoice->grand_total ?? 0);

                $this->validatePaymentAmount($grandTotal, $totalBayar);
                $this->markPaymentFinishTrace($trace, 'validatePaymentAmount.finished', [
                    'grand_total' => $grandTotal,
                    'total_bayar' => $totalBayar,
                ]);

                $this->replaceInvoiceMetode($invoice, $metode, $jenisTransaksi);
                $this->markPaymentFinishTrace($trace, 'replaceInvoiceMetode.finished');

                if ($jenisTransaksi === 4) {
                    $selectedDepositItems = $this->resolveDepositItems($request, $invoice);
                    $selectedDepositItemIds = array_keys($selectedDepositItems);
                    $this->markPaymentFinishTrace($trace, 'resolveDepositItems.finished', [
                        'selected_deposit_item_count' => count($selectedDepositItemIds),
                    ]);

                    $this->applyDepositTransactionRules($invoice, $request, $depositExpiredAt, $selectedDepositItemIds);
                    $this->markPaymentFinishTrace($trace, 'applyDepositTransactionRules.finished');

                    $this->generateDepositTreatmentRecords($invoice, $selectedDepositItems);
                    $this->markPaymentFinishTrace($trace, 'generateDepositTreatmentRecords.finished');
                } else {
                    $this->markPaymentFinishTrace($trace, 'depositTransactionRules.skipped');
                }

                $this->processStockKeluarPembayaran($invoice, $registrasi);
                $this->markPaymentFinishTrace($trace, 'processStockKeluarPembayaran.finished');

                $this->createAccurateSyncPendingLog($invoice, $registrasi);
                $this->markPaymentFinishTrace($trace, 'createAccurateSyncPendingLog.finished');

                $invoice->update($this->onlyExistingColumns('pembayaran_invoice', [
                    'tanggal_lunas' => now(),
                    'jenis_transaksi' => $jenisTransaksi,
                    'is_premier' => $isPremier,
                    'referensi_dokter_id' => $jenisTransaksi === 4
                        ? $request->input('referensi_dokter_id')
                        : ($request->input('referensi_dokter_id', $invoice->referensi_dokter_id ?? null)),
                    'deposit_expired_option_id' => $jenisTransaksi === 4
                        ? $request->input('deposit_expired_option_id')
                        : ($invoice->deposit_expired_option_id ?? null),
                    'deposit_expired_at' => $jenisTransaksi === 4
                        ? $depositExpiredAt
                        : ($invoice->deposit_expired_at ?? null),
                    'sumber_informasi_id' => $request->input('sumber_informasi_id', $invoice->sumber_informasi_id ?? null),
                    'sumber_kedatangan' => $request->input('sumber_kedatangan', $invoice->sumber_kedatangan ?? null),
                    'catatan' => $request->input('catatan_pembayaran', $invoice->catatan ?? null),
                    'grand_total' => $grandTotal,
                    'total_bayar' => $totalBayar,
                    'sisa_tagihan' => 0,
                    'total_kembalian' => max(0, $totalBayar - $grandTotal),
                    'status' => PembayaranInvoice::STATUS_LUNAS,
                    'updated_by' => $this->username(),
                    'updated_at' => now(),
                ]));
                $this->markPaymentFinishTrace($trace, 'invoice.markPaid.finished', [
                    'invoice_id' => $invoice->id,
                    'status' => PembayaranInvoice::STATUS_LUNAS,
                ]);

                $invoice->refresh();
                $this->markPaymentFinishTrace($trace, 'invoice.refresh.afterPaid.finished');

                $this->memberPointService->processEarnedPointLedger($invoice, $this->username());
                $this->markPaymentFinishTrace($trace, 'memberPointService.processEarnedPointLedger.finished');

                $memberReward = $this->buildPaymentMemberRewardResponse($invoice, $memberBeforeLedger);
                $this->markPaymentFinishTrace($trace, 'buildPaymentMemberRewardResponse.finished', [
                    'member_reward' => $memberReward,
                ]);

                $nextTask = $this->completePaymentTask($registrasi);
                $this->markPaymentFinishTrace($trace, 'completePaymentTask.finished', [
                    'next_task_id' => $nextTask?->id,
                ]);

                $result = [
                    'invoice_id' => $invoice->id,
                    'next_task_id' => $nextTask?->id,
                    'already_paid' => false,
                    'member_reward' => $memberReward,
                ];

                $this->markPaymentFinishTrace($trace, 'transaction.returning', $result);

                return $result;
            }, 3);

            $this->markPaymentFinishTrace($trace, 'transaction.committed', [
                'invoice_id' => $result['invoice_id'] ?? null,
                'next_task_id' => $result['next_task_id'] ?? null,
            ]);

            $response = $this->buildFinishSuccessResponse($result);
            $this->markPaymentFinishTrace($trace, 'response.built');

            return $response;
        } catch (ValidationException $e) {
            $this->markPaymentFinishTrace($trace, 'exception.validation', [
                'message' => collect($e->errors())->flatten()->first(),
                'errors' => $e->errors(),
            ]);

            return response()->json([
                'status' => false,
                'message' => collect($e->errors())->flatten()->first() ?: 'Validasi gagal',
                'errors' => $e->errors(),
            ], 422);
        } catch (Throwable $e) {
            $this->markPaymentFinishTrace($trace, 'exception.throwable', [
                'exception' => get_class($e),
                'message' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
            ]);

            report($e);

            return response()->json([
                'status' => false,
                'message' => 'Gagal menyelesaikan pembayaran',
                'error' => $e->getMessage(),
            ], 500);
        }
    }
}
