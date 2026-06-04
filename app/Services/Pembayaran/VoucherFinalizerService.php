<?php

namespace App\Services\Pembayaran;

use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

class VoucherFinalizerService
{
    public function applySelectedPromosFromRequest(object $invoice, Request $request, string $username = 'system'): array
    {
        $this->assertRequiredTables();

        $selectedPromos = $this->normalizeSelectedPromos($request);

        $this->softDeleteInvoicePromos((int) $invoice->id, $username);
        $this->resetInvoicePromoAmount($invoice, $username);

        if (count($selectedPromos) === 0) {
            return [
                'total_promo' => 0.0,
                'voucher_ids' => [],
            ];
        }

        $voucherIds = collect($selectedPromos)
            ->pluck('voucher_id')
            ->filter()
            ->map(fn ($value) => (int) $value)
            ->unique()
            ->values()
            ->all();

        $voucherCodes = collect($selectedPromos)
            ->pluck('kode_voucher')
            ->filter()
            ->map(fn ($value) => trim((string) $value))
            ->filter(fn ($value) => $value !== '')
            ->unique()
            ->values()
            ->all();

        $vouchers = $this->loadVouchersForUpdate($voucherIds, $voucherCodes);

        if ($vouchers->isEmpty()) {
            throw ValidationException::withMessages([
                'promo' => 'Voucher tidak ditemukan atau sudah tidak aktif.',
            ]);
        }

        $this->assertNoDuplicateVoucher($selectedPromos);
        $this->assertCombinable($vouchers);

        $invoiceItems = $this->loadInvoiceItems((int) $invoice->id);

        $totalPromo = 0.0;
        $usedVoucherIds = [];

        foreach ($selectedPromos as $selected) {
            $voucher = $this->resolveSelectedVoucher($selected, $vouchers);

            if (!$voucher) {
                throw ValidationException::withMessages([
                    'promo' => 'Voucher ' . ($selected['kode_voucher'] ?: $selected['voucher_id'] ?: '-') . ' tidak valid.',
                ]);
            }

            $this->validateVoucherForInvoice($voucher, $invoice, $selected);

            $allocations = $this->calculateVoucherAllocations($voucher, $invoiceItems);
            $voucherAmount = array_sum(array_column($allocations, 'amount'));

            if ($voucherAmount <= 0) {
                throw ValidationException::withMessages([
                    'promo' => 'Voucher ' . $this->voucherName($voucher) . ' tidak memiliki nilai diskon untuk item invoice ini.',
                ]);
            }

            $remainingInvoiceBase = max($this->getInvoicePromoBase($invoiceItems) - $totalPromo, 0);

            if ($remainingInvoiceBase <= 0) {
                break;
            }

            if ($voucherAmount > $remainingInvoiceBase) {
                $allocations = $this->capAllocations($allocations, $remainingInvoiceBase);
                $voucherAmount = array_sum(array_column($allocations, 'amount'));
            }

            if ($voucherAmount <= 0) {
                continue;
            }

            $this->consumeVoucherQuotaIfNeeded($voucher, $username);
            $this->redeemVoucherCodeIfNeeded($voucher, $invoice, $selected, $username);
            $this->insertInvoicePromoRows($invoice, $voucher, $selected, $allocations, $username);

            $totalPromo += $voucherAmount;
            $usedVoucherIds[] = (int) $voucher->id;
        }

        $this->updateInvoicePromoAmount($invoice, $totalPromo, $username);

        return [
            'total_promo' => round($totalPromo, 2),
            'voucher_ids' => array_values(array_unique($usedVoucherIds)),
        ];
    }

    public function restoreVoucherAfterCancel(object $invoice, string $username = 'system'): void
    {
        if (!Schema::hasTable('pembayaran_invoice_promo')) {
            return;
        }

        $promoRows = DB::table('pembayaran_invoice_promo')
            ->where('pembayaran_id', $invoice->id)
            ->where(function ($q) {
                $q->whereNull('is_delete')->orWhere('is_delete', 0);
            })
            ->lockForUpdate()
            ->get();

        if ($promoRows->isEmpty()) {
            return;
        }

        $voucherIds = $promoRows
            ->pluck('voucher_id')
            ->filter()
            ->map(fn ($id) => (int) $id)
            ->unique()
            ->values();

        foreach ($voucherIds as $voucherId) {
            $voucher = DB::table('master_voucher_diskon')
                ->where('id', $voucherId)
                ->lockForUpdate()
                ->first();

            if (!$voucher) {
                continue;
            }

            $this->restoreVoucherQuotaIfNeeded($voucher, $username);
        }

        if (Schema::hasTable('master_voucher_diskon_kode')) {
            DB::table('master_voucher_diskon_kode')
                ->where('redeemed_invoice_no', $invoice->no_invoice)
                ->where('status_kode', 2)
                ->where(function ($q) {
                    $q->whereNull('is_delete')->orWhere('is_delete', 0);
                })
                ->update($this->onlyExistingColumns('master_voucher_diskon_kode', [
                    'status_kode' => 1,
                    'used_at' => null,
                    'redeemed_invoice_no' => null,
                    'redeemed_pasien_id' => null,
                    'updated_by' => $username,
                    'updated_at' => now(),
                ]));
        }

        DB::table('pembayaran_invoice_promo')
            ->where('pembayaran_id', $invoice->id)
            ->where(function ($q) {
                $q->whereNull('is_delete')->orWhere('is_delete', 0);
            })
            ->update($this->onlyExistingColumns('pembayaran_invoice_promo', [
                'is_delete' => 1,
                'updated_by' => $username,
                'updated_at' => now(),
            ]));

        $this->resetInvoicePromoAmount($invoice, $username);
    }

    protected function assertRequiredTables(): void
    {
        foreach (['pembayaran_invoice_promo', 'master_voucher_diskon'] as $table) {
            if (!Schema::hasTable($table)) {
                throw ValidationException::withMessages([
                    'promo' => "Tabel {$table} belum tersedia.",
                ]);
            }
        }
    }

    protected function normalizeSelectedPromos(Request $request): array
    {
        $rows = [];

        foreach (['promos', 'selected_promos', 'applied_promos'] as $payloadKey) {
            foreach ((array) $request->input($payloadKey, []) as $row) {
                if (!is_array($row)) {
                    continue;
                }

                $voucherId = (int) ($row['voucher_id'] ?? $row['id'] ?? $row['master_voucher_diskon_id'] ?? 0);
                $kodeVoucher = trim((string) ($row['kode_voucher'] ?? $row['kode'] ?? $row['code'] ?? ''));

                if ($voucherId > 0 || $kodeVoucher !== '') {
                    $rows[] = [
                        'voucher_id' => $voucherId > 0 ? $voucherId : null,
                        'kode_voucher' => $kodeVoucher !== '' ? $kodeVoucher : null,
                        'client_amount' => (float) ($row['diskon_amount'] ?? $row['amount'] ?? 0),
                    ];
                }
            }
        }

        foreach ((array) $request->input('promo_ids', []) as $id) {
            $voucherId = (int) $id;

            if ($voucherId > 0) {
                $rows[] = [
                    'voucher_id' => $voucherId,
                    'kode_voucher' => null,
                    'client_amount' => 0,
                ];
            }
        }

        $unique = [];

        foreach ($rows as $row) {
            $key = ($row['voucher_id'] ?: 'code') . '|' . strtolower((string) ($row['kode_voucher'] ?? ''));

            if (!isset($unique[$key])) {
                $unique[$key] = $row;
                continue;
            }

            if (empty($unique[$key]['kode_voucher']) && !empty($row['kode_voucher'])) {
                $unique[$key]['kode_voucher'] = $row['kode_voucher'];
            }

            if ((float) ($unique[$key]['client_amount'] ?? 0) <= 0 && (float) ($row['client_amount'] ?? 0) > 0) {
                $unique[$key]['client_amount'] = (float) $row['client_amount'];
            }
        }

        return array_values($unique);
    }

    protected function loadVouchersForUpdate(array $voucherIds, array $voucherCodes)
    {
        return DB::table('master_voucher_diskon')
            ->where(function ($q) use ($voucherIds, $voucherCodes) {
                if (count($voucherIds) > 0) {
                    $q->orWhereIn('id', $voucherIds);
                }

                if (count($voucherCodes) > 0) {
                    $q->orWhereIn('kode_voucher', $voucherCodes);
                }

                if (Schema::hasTable('master_voucher_diskon_kode') && count($voucherCodes) > 0) {
                    $kodeVoucherIds = DB::table('master_voucher_diskon_kode')
                        ->whereIn('kode_voucher', $voucherCodes)
                        ->where(function ($sub) {
                            $sub->whereNull('is_delete')->orWhere('is_delete', 0);
                        })
                        ->pluck('voucher_diskon_id')
                        ->unique()
                        ->values()
                        ->all();

                    if (count($kodeVoucherIds) > 0) {
                        $q->orWhereIn('id', $kodeVoucherIds);
                    }
                }
            })
            ->where(function ($q) {
                $q->whereNull('is_delete')->orWhere('is_delete', 0);
            })
            ->lockForUpdate()
            ->get()
            ->keyBy('id');
    }

    protected function assertNoDuplicateVoucher(array $selectedPromos): void
    {
        $ids = collect($selectedPromos)->pluck('voucher_id')->filter()->map(fn ($id) => (int) $id);

        if ($ids->count() !== $ids->unique()->count()) {
            throw ValidationException::withMessages([
                'promo' => 'Voucher yang sama tidak boleh digunakan lebih dari satu kali.',
            ]);
        }
    }

    protected function assertCombinable($vouchers): void
    {
        if ($vouchers->count() <= 1) {
            return;
        }

        $hasNonCombine = $vouchers->contains(fn ($voucher) => (int) ($voucher->is_bisa_digabung_promo ?? 0) !== 1);

        if ($hasNonCombine) {
            throw ValidationException::withMessages([
                'promo' => 'Voucher tidak dapat digabung dengan promo lain.',
            ]);
        }
    }

    protected function resolveSelectedVoucher(array $selected, $vouchers): ?object
    {
        if (!empty($selected['voucher_id']) && $vouchers->has((int) $selected['voucher_id'])) {
            return $vouchers->get((int) $selected['voucher_id']);
        }

        $kode = trim((string) ($selected['kode_voucher'] ?? ''));

        if ($kode === '') {
            return null;
        }

        $direct = $vouchers->first(fn ($voucher) => (string) ($voucher->kode_voucher ?? '') === $kode);

        if ($direct) {
            return $direct;
        }

        if (!Schema::hasTable('master_voucher_diskon_kode')) {
            return null;
        }

        $kodeRow = DB::table('master_voucher_diskon_kode')
            ->where('kode_voucher', $kode)
            ->where(function ($q) {
                $q->whereNull('is_delete')->orWhere('is_delete', 0);
            })
            ->lockForUpdate()
            ->first();

        if (!$kodeRow) {
            return null;
        }

        return $vouchers->get((int) $kodeRow->voucher_diskon_id);
    }

    protected function validateVoucherForInvoice(object $voucher, object $invoice, array $selected): void
    {
        if ((int) ($voucher->status_voucher ?? 0) !== 1) {
            throw ValidationException::withMessages(['promo' => 'Voucher ' . $this->voucherName($voucher) . ' tidak aktif.']);
        }

        if ((int) ($voucher->is_all_toko ?? 0) !== 1 && !empty($voucher->toko_id) && (int) $voucher->toko_id !== (int) $invoice->toko_id) {
            throw ValidationException::withMessages(['promo' => 'Voucher ' . $this->voucherName($voucher) . ' tidak berlaku untuk cabang ini.']);
        }

        if ((int) ($voucher->is_unlimited_date ?? 0) !== 1) {
            $tanggalInvoice = Carbon::parse($invoice->tanggal_invoice ?? now())->toDateString();

            if (!empty($voucher->tanggal_mulai) && $tanggalInvoice < Carbon::parse($voucher->tanggal_mulai)->toDateString()) {
                throw ValidationException::withMessages(['promo' => 'Voucher ' . $this->voucherName($voucher) . ' belum berlaku.']);
            }

            if (!empty($voucher->tanggal_akhir) && $tanggalInvoice > Carbon::parse($voucher->tanggal_akhir)->toDateString()) {
                throw ValidationException::withMessages(['promo' => 'Voucher ' . $this->voucherName($voucher) . ' sudah expired.']);
            }
        }

        $kode = trim((string) ($selected['kode_voucher'] ?? ''));

        if ($kode !== '' && $this->isGenerateVoucher($voucher)) {
            $this->assertVoucherCodeAvailable($voucher, $invoice, $kode);
        }

        if (!$this->isGenerateVoucher($voucher) && (int) ($voucher->is_unlimited_generate ?? 0) !== 1 && (int) ($voucher->qty_generate ?? 0) <= 0) {
            throw ValidationException::withMessages(['promo' => 'Kuota voucher ' . $this->voucherName($voucher) . ' sudah habis.']);
        }
    }

    protected function assertVoucherCodeAvailable(object $voucher, object $invoice, string $kode): void
    {
        if (!Schema::hasTable('master_voucher_diskon_kode')) {
            throw ValidationException::withMessages(['promo' => 'Tabel kode voucher belum tersedia.']);
        }

        $row = DB::table('master_voucher_diskon_kode')
            ->where('voucher_diskon_id', $voucher->id)
            ->where('kode_voucher', $kode)
            ->where(function ($q) {
                $q->whereNull('is_delete')->orWhere('is_delete', 0);
            })
            ->lockForUpdate()
            ->first();

        if (!$row) {
            throw ValidationException::withMessages(['promo' => 'Kode voucher tidak ditemukan.']);
        }

        $isAvailable = (int) ($row->status_kode ?? 0) === 1;
        $isAlreadyUsedByThisInvoice = (int) ($row->status_kode ?? 0) === 2
            && (string) ($row->redeemed_invoice_no ?? '') === (string) ($invoice->no_invoice ?? '');

        if (!$isAvailable && !$isAlreadyUsedByThisInvoice) {
            throw ValidationException::withMessages(['promo' => 'Kode voucher sudah digunakan atau tidak tersedia.']);
        }

        if (!empty($row->expired_at) && Carbon::parse($row->expired_at)->lt(now()) && !$isAlreadyUsedByThisInvoice) {
            throw ValidationException::withMessages(['promo' => 'Kode voucher sudah expired.']);
        }
    }

    protected function loadInvoiceItems(int $invoiceId)
    {
        return DB::table('pembayaran_invoice_item')
            ->where('pembayaran_id', $invoiceId)
            ->whereIn('item_type', [2, 3])
            ->where(function ($q) {
                $q->whereNull('is_delete')->orWhere('is_delete', 0);
            })
            ->lockForUpdate()
            ->get();
    }

    protected function calculateVoucherAllocations(object $voucher, $invoiceItems): array
    {
        $specificItems = $this->loadVoucherSpecificItems((int) $voucher->id);

        if ($this->isValueVoucher($voucher)) {
            return $this->calculateInvoiceScopeAllocation($voucher, $invoiceItems);
        }

        if ($this->isBundlingVoucher($voucher)) {
            return $this->calculateBundlingAllocations($voucher, $specificItems, $invoiceItems);
        }

        if ($specificItems->isNotEmpty()) {
            return $this->calculateSpecificItemAllocations($voucher, $specificItems, $invoiceItems);
        }

        $eligibleItems = $this->filterItemsByVoucherKind($voucher, $invoiceItems);

        return $this->calculateInvoiceScopeAllocation($voucher, $eligibleItems);
    }

    protected function loadVoucherSpecificItems(int $voucherId)
    {
        if (!Schema::hasTable('master_voucher_diskon_item')) {
            return collect();
        }

        return DB::table('master_voucher_diskon_item')
            ->where('voucher_diskon_id', $voucherId)
            ->where(function ($q) {
                $q->whereNull('is_delete')->orWhere('is_delete', 0);
            })
            ->get();
    }

    protected function calculateInvoiceScopeAllocation(object $voucher, $items): array
    {
        $base = (float) $items->sum(fn ($item) => $this->itemNetBase($item));

        $amount = $this->calculateDiscountAmount(
            $base,
            (string) ($voucher->tipe_diskon ?? 'nominal'),
            (float) ($voucher->total_diskon ?? 0),
            (float) ($voucher->total_diskon_maksimal ?? 0)
        );

        return $amount > 0 ? [[
            'pembayaran_item_id' => null,
            'scope_type' => 1,
            'amount' => $amount,
            'base' => $base,
        ]] : [];
    }

    protected function calculateSpecificItemAllocations(object $voucher, $specificItems, $invoiceItems): array
    {
        $allocations = [];

        foreach ($specificItems as $specific) {
            $matched = $this->matchInvoiceItemsByVoucherSpecificItem($specific, $invoiceItems);

            foreach ($matched as $item) {
                $base = $this->itemNetBase($item);
                $tipe = $specific->tipe_diskon_item ?: $voucher->tipe_diskon;
                $nilai = $specific->nilai_diskon_item !== null
                    ? (float) $specific->nilai_diskon_item
                    : (float) $voucher->total_diskon;

                $amount = $this->calculateDiscountAmount(
                    $base,
                    (string) $tipe,
                    $nilai,
                    (float) ($voucher->total_diskon_maksimal ?? 0)
                );

                if ($amount > 0) {
                    $allocations[] = [
                        'pembayaran_item_id' => (int) $item->id,
                        'scope_type' => 2,
                        'amount' => min($amount, $base),
                        'base' => $base,
                    ];
                }
            }
        }

        return $allocations;
    }

    protected function calculateBundlingAllocations(object $voucher, $specificItems, $invoiceItems): array
    {
        $treatmentItems = $invoiceItems->filter(fn ($item) => (int) ($item->item_type ?? 0) === 2);
        $productItems = $invoiceItems->filter(fn ($item) => (int) ($item->item_type ?? 0) === 3);

        if ($specificItems->isNotEmpty()) {
            $specificTreatmentRows = $specificItems->filter(fn ($row) => strtolower((string) ($row->item_type ?? '')) === 'treatment');
            $specificProductRows = $specificItems->filter(fn ($row) => in_array(strtolower((string) ($row->item_type ?? '')), ['produk', 'product'], true));

            /*
             * Bundling in master data can be configured in two valid forms:
             *
             * 1. Item-specific bundling:
             *    master_voucher_diskon_item contains exact items and each row may contain
             *    tipe_diskon_item / nilai_diskon_item. Example:
             *    - Treatment A: Rp 20.000
             *    - Treatment B: Rp 80.000
             *
             *    In this form, do not force product + treatment. The selected invoice only
             *    needs to contain all target rows that exist in the voucher detail.
             *
             * 2. General product-treatment bundling:
             *    master_voucher_diskon_item is empty. In this form, require at least one
             *    treatment and one product, then apply the voucher nominal/percent rule.
             */
            $hasPerItemRule = $specificItems->contains(function ($row) {
                return $row->tipe_diskon_item !== null || $row->nilai_diskon_item !== null;
            });

            if ($hasPerItemRule || $specificProductRows->isEmpty() || $specificTreatmentRows->isEmpty()) {
                return $this->calculateSpecificItemAllocations($voucher, $specificItems, $invoiceItems);
            }

            $matchedTreatmentItems = $this->filterBundlingSpecificItems($specificTreatmentRows, $treatmentItems);
            $matchedProductItems = $this->filterBundlingSpecificItems($specificProductRows, $productItems);

            if ($matchedTreatmentItems->isEmpty() || $matchedProductItems->isEmpty()) {
                return [];
            }

            $eligibleItems = $matchedTreatmentItems
                ->merge($matchedProductItems)
                ->unique('id')
                ->values();

            $type = strtolower(trim((string) ($voucher->tipe_diskon ?? 'nominal')));

            if (in_array($type, ['percent', 'persen', '%', '1'], true)) {
                $base = (float) $eligibleItems->sum(fn ($item) => $this->itemNetBase($item));

                $amount = $this->calculateDiscountAmount(
                    $base,
                    $type,
                    (float) ($voucher->total_diskon ?? 0),
                    (float) ($voucher->total_diskon_maksimal ?? 0)
                );

                return $this->distributeAmountAcrossItems($eligibleItems, $amount);
            }

            return $this->distributeAmountAcrossItems(
                $eligibleItems,
                (float) ($voucher->total_diskon ?? 0)
            );
        }

        if ($treatmentItems->isEmpty() || $productItems->isEmpty()) {
            return [];
        }

        $type = strtolower(trim((string) ($voucher->tipe_diskon ?? 'nominal')));
        $eligibleItems = $treatmentItems->merge($productItems)->unique('id')->values();

        if (in_array($type, ['percent', 'persen', '%', '1'], true)) {
            $base = (float) $eligibleItems->sum(fn ($item) => $this->itemNetBase($item));

            $amount = $this->calculateDiscountAmount(
                $base,
                $type,
                (float) ($voucher->total_diskon ?? 0),
                (float) ($voucher->total_diskon_maksimal ?? 0)
            );

            return $this->distributeAmountAcrossItems($eligibleItems, $amount);
        }

        /*
         * For general bundle without item detail, nominal value is applied once to
         * the eligible bundle set. Do not multiply it silently because that makes
         * FE and BE difficult to audit.
         */
        return $this->distributeAmountAcrossItems(
            $eligibleItems,
            (float) ($voucher->total_diskon ?? 0)
        );
    }

    protected function filterBundlingSpecificItems($specificItems, $invoiceItems)
    {
        $matchedById = [];

        foreach ($specificItems as $specific) {
            $matched = $this->matchInvoiceItemsByVoucherSpecificItem($specific, $invoiceItems);

            foreach ($matched as $item) {
                $matchedById[(int) $item->id] = $item;
            }
        }

        return collect(array_values($matchedById));
    }

    protected function matchInvoiceItemsByVoucherSpecificItem(object $specific, $invoiceItems)
    {
        return $invoiceItems->filter(function ($item) use ($specific) {
            $specificType = strtolower((string) ($specific->item_type ?? ''));

            if ($specificType === 'treatment') {
                return (int) ($item->item_type ?? 0) === 2
                    && (int) ($item->treatment_id ?? 0) === (int) $specific->item_id;
            }

            if (in_array($specificType, ['produk', 'product'], true)) {
                return (int) ($item->item_type ?? 0) === 3
                    && (int) ($item->produk_id ?? 0) === (int) $specific->item_id;
            }

            return false;
        });
    }

    protected function distributeAmountAcrossItems($items, float $amount): array
    {
        $amount = round($amount, 2);
        $items = $items->values();
        $base = (float) $items->sum(fn ($item) => $this->itemNetBase($item));

        if ($amount <= 0 || $base <= 0 || $items->isEmpty()) {
            return [];
        }

        $allocations = [];
        $remaining = $amount;
        $lastIndex = $items->count() - 1;

        foreach ($items as $index => $item) {
            $itemBase = $this->itemNetBase($item);

            if ($itemBase <= 0) {
                continue;
            }

            $itemAmount = $index === $lastIndex
                ? $remaining
                : round(($amount * $itemBase) / $base, 2);

            $itemAmount = min($itemAmount, $itemBase, $remaining);

            if ($itemAmount <= 0) {
                continue;
            }

            $allocations[] = [
                'pembayaran_item_id' => (int) $item->id,
                'scope_type' => 2,
                'amount' => round($itemAmount, 2),
                'base' => $itemBase,
            ];

            $remaining = round($remaining - $itemAmount, 2);

            if ($remaining <= 0) {
                break;
            }
        }

        return $allocations;
    }

    protected function filterItemsByVoucherKind(object $voucher, $items)
    {
        $jenis = (int) ($voucher->jenis_voucher_id ?? 0);

        return $items->filter(function ($item) use ($jenis) {
            $itemType = (int) ($item->item_type ?? 0);

            if ($jenis === 1) {
                return $itemType === 2;
            }

            if ($jenis === 2) {
                return $itemType === 3;
            }

            return in_array($itemType, [2, 3], true);
        });
    }

    protected function itemNetBase(object $item): float
    {
        $afterSubtotalDiscount = (float) ($item->subtotal_after_diskon_subtotal ?? 0);

        if ($afterSubtotalDiscount > 0) {
            return $afterSubtotalDiscount;
        }

        return max((float) ($item->subtotal ?? 0) - (float) ($item->diskon_subtotal_amount ?? 0), 0);
    }

    protected function getInvoicePromoBase($items): float
    {
        return (float) $items->sum(fn ($item) => $this->itemNetBase($item));
    }

    protected function calculateDiscountAmount(float $base, string $tipe, float $nilai, float $maximal = 0): float
    {
        if ($base <= 0 || $nilai <= 0) {
            return 0.0;
        }

        $type = strtolower(trim($tipe));

        if (in_array($type, ['percent', 'persen', '%', '1'], true)) {
            $amount = round(($base * $nilai) / 100, 2);

            if ($maximal > 0) {
                $amount = min($amount, $maximal);
            }

            return min($amount, $base);
        }

        return min(round($nilai, 2), $base);
    }

    protected function capAllocations(array $allocations, float $cap): array
    {
        $result = [];
        $remaining = $cap;

        foreach ($allocations as $allocation) {
            if ($remaining <= 0) {
                break;
            }

            $amount = min((float) $allocation['amount'], $remaining);

            if ($amount > 0) {
                $allocation['amount'] = round($amount, 2);
                $result[] = $allocation;
                $remaining -= $amount;
            }
        }

        return $result;
    }

    protected function consumeVoucherQuotaIfNeeded(object $voucher, string $username): void
    {
        if ($this->isGenerateVoucher($voucher)) {
            return;
        }

        if ((int) ($voucher->is_unlimited_generate ?? 0) === 1) {
            return;
        }

        $affected = DB::table('master_voucher_diskon')
            ->where('id', $voucher->id)
            ->where('qty_generate', '>', 0)
            ->decrement('qty_generate', 1, $this->onlyExistingColumns('master_voucher_diskon', [
                'updated_by' => $username,
                'updated_at' => now(),
            ]));

        if ($affected < 1) {
            throw ValidationException::withMessages([
                'promo' => 'Kuota voucher ' . $this->voucherName($voucher) . ' sudah habis.',
            ]);
        }
    }

    protected function restoreVoucherQuotaIfNeeded(object $voucher, string $username): void
    {
        if ($this->isGenerateVoucher($voucher)) {
            return;
        }

        if ((int) ($voucher->is_unlimited_generate ?? 0) === 1) {
            return;
        }

        DB::table('master_voucher_diskon')
            ->where('id', $voucher->id)
            ->increment('qty_generate', 1, $this->onlyExistingColumns('master_voucher_diskon', [
                'updated_by' => $username,
                'updated_at' => now(),
            ]));
    }

    protected function redeemVoucherCodeIfNeeded(object $voucher, object $invoice, array $selected, string $username): void
    {
        $kode = trim((string) ($selected['kode_voucher'] ?? ''));

        if ($kode === '' || !$this->isGenerateVoucher($voucher) || !Schema::hasTable('master_voucher_diskon_kode')) {
            return;
        }

        $affected = DB::table('master_voucher_diskon_kode')
            ->where('voucher_diskon_id', $voucher->id)
            ->where('kode_voucher', $kode)
            ->where(function ($q) use ($invoice) {
                $q->where('status_kode', 1)
                    ->orWhere(function ($sub) use ($invoice) {
                        $sub->where('status_kode', 2)
                            ->where('redeemed_invoice_no', $invoice->no_invoice);
                    });
            })
            ->where(function ($q) {
                $q->whereNull('is_delete')->orWhere('is_delete', 0);
            })
            ->update($this->onlyExistingColumns('master_voucher_diskon_kode', [
                'status_kode' => 2,
                'used_at' => now(),
                'redeemed_invoice_no' => $invoice->no_invoice,
                'redeemed_pasien_id' => $invoice->pasien_id ?? null,
                'updated_by' => $username,
                'updated_at' => now(),
            ]));

        if ($affected < 1) {
            throw ValidationException::withMessages([
                'promo' => 'Kode voucher gagal diredeem. Silakan refresh dan coba lagi.',
            ]);
        }
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

    protected function insertInvoicePromoRows(object $invoice, object $voucher, array $selected, array $allocations, string $username): void
    {
        foreach ($allocations as $allocation) {
            DB::table('pembayaran_invoice_promo')->insert($this->onlyExistingColumns('pembayaran_invoice_promo', [
                'pembayaran_id' => $invoice->id,
                'pembayaran_item_id' => $allocation['pembayaran_item_id'],
                'voucher_id' => $voucher->id,
                'kode_voucher' => $selected['kode_voucher'] ?: $voucher->kode_voucher,
                'nama_voucher' => $voucher->nama_voucher ?? $voucher->kode_voucher ?? 'Voucher',
                'scope_type' => $allocation['scope_type'],
                'diskon_tipe' => $this->normalizePromoDiscountType($voucher->tipe_diskon ?? $voucher->diskon_tipe ?? 2),
                'diskon_nilai' => $voucher->total_diskon ?? 0,
                'diskon_amount' => round((float) $allocation['amount'], 2),
                'catatan' => 'Validated by backend finalizer',
                'is_delete' => 0,
                'created_by' => $username,
                'updated_by' => $username,
                'created_at' => now(),
                'updated_at' => now(),
            ]));
        }
    }

    protected function softDeleteInvoicePromos(int $invoiceId, string $username): void
    {
        if (!Schema::hasTable('pembayaran_invoice_promo')) {
            return;
        }

        DB::table('pembayaran_invoice_promo')
            ->where('pembayaran_id', $invoiceId)
            ->where(function ($q) {
                $q->whereNull('is_delete')->orWhere('is_delete', 0);
            })
            ->update($this->onlyExistingColumns('pembayaran_invoice_promo', [
                'is_delete' => 1,
                'updated_by' => $username,
                'updated_at' => now(),
            ]));
    }

    protected function updateInvoicePromoAmount(object $invoice, float $totalPromo, string $username): void
    {
        $subtotal = (float) ($invoice->subtotal ?? 0);
        $diskonSubtotal = (float) ($invoice->diskon_subtotal_amount ?? 0);
        $diskonMember = (float) ($invoice->diskon_member_amount ?? 0);

        $grandTotal = max($subtotal - $diskonSubtotal - $totalPromo - $diskonMember, 0);

        DB::table('pembayaran_invoice')
            ->where('id', $invoice->id)
            ->update($this->onlyExistingColumns('pembayaran_invoice', [
                'total_promo' => round($totalPromo, 2),
                'grand_total' => round($grandTotal, 2),
                'sisa_tagihan' => round(max($grandTotal - (float) ($invoice->total_bayar ?? 0), 0), 2),
                'updated_by' => $username,
                'updated_at' => now(),
            ]));
    }

    protected function resetInvoicePromoAmount(object $invoice, string $username): void
    {
        DB::table('pembayaran_invoice')
            ->where('id', $invoice->id)
            ->update($this->onlyExistingColumns('pembayaran_invoice', [
                'total_promo' => 0,
                'grand_total' => max((float) ($invoice->subtotal ?? 0) - (float) ($invoice->diskon_subtotal_amount ?? 0) - (float) ($invoice->diskon_member_amount ?? 0), 0),
                'updated_by' => $username,
                'updated_at' => now(),
            ]));
    }

    protected function isGenerateVoucher(object $voucher): bool
    {
        return strtolower((string) ($voucher->mode_voucher ?? 'direct')) === 'generate';
    }

    protected function isValueVoucher(object $voucher): bool
    {
        return (int) ($voucher->jenis_voucher_id ?? 0) === 4;
    }

    protected function isBundlingVoucher(object $voucher): bool
    {
        return (int) ($voucher->jenis_voucher_id ?? 0) === 3;
    }

    protected function voucherName(object $voucher): string
    {
        return (string) ($voucher->nama_voucher ?? $voucher->kode_voucher ?? 'Voucher');
    }

    protected function onlyExistingColumns(string $table, array $payload): array
    {
        if (!Schema::hasTable($table)) {
            return [];
        }

        $columns = Schema::getColumnListing($table);

        return collect($payload)
            ->filter(fn ($value, $column) => in_array($column, $columns, true))
            ->all();
    }
}
