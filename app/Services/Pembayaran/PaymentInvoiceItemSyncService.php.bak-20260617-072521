<?php

namespace App\Services\Pembayaran;

use App\Models\Pembayaran\PembayaranInvoice;
use App\Models\Pembayaran\PembayaranInvoiceItem;
use App\Models\Registrasi\RegistrasiKunjungan;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class PaymentInvoiceItemSyncService
{
    protected array $tableExistsCache = [];

    protected array $tableColumnCache = [];

    protected ?string $usernameCache = null;

    public function syncInvoiceItemsFromRequest(Request $request, PembayaranInvoice $invoice): void
    {
        if (!$this->hasTable('pembayaran_invoice_item')) {
            return;
        }

        if ($request->has('treatment_items')) {
            $this->syncRequestTreatmentItems($request, $invoice);
        }

        if ($request->has('penjualan_items')) {
            $this->syncRequestProductItems($request, $invoice);
        }

        $invoice->load('items');
    }

    protected function syncRequestTreatmentItems(Request $request, PembayaranInvoice $invoice): void
    {
        $rows = collect((array) $request->input('treatment_items', []))
            ->filter(fn ($row) => is_array($row))
            ->values();

        $activeItemIds = [];

        foreach ($rows as $row) {
            $itemId = (int) ($row['invoice_item_id'] ?? $row['id'] ?? 0);
            $sourceDetailId = (int) ($row['registrasi_treatment_detail_id'] ?? $row['source_detail_id'] ?? 0);
            $qty = max((float) ($row['qty'] ?? $row['jumlah'] ?? 1), 1);
            $harga = (float) ($row['harga'] ?? $row['harga_treatment'] ?? $row['tarif'] ?? $row['harga_terendah'] ?? 0);
            $gross = $harga * $qty;

            $diskonTipe = $this->normalizeItemDiscountType(
                $row['diskon_type'] ?? $row['manual_diskon_type'] ?? $row['diskon_tipe'] ?? 0
            );
            $diskonNilai = (float) ($row['diskon'] ?? $row['manual_diskon'] ?? $row['diskon_nilai'] ?? 0);
            $diskonAmount = $this->calculateInvoiceItemDiscountAmount($gross, $diskonTipe, $diskonNilai);
            $diskonReferral = (float) ($row['diskon_referral'] ?? 0);
            $promoDiskon = (float) ($row['promo_diskon_amount'] ?? 0);
            $subtotal = max($gross - $diskonAmount - $diskonReferral - $promoDiskon, 0);

            $sourceType = $sourceDetailId > 0 ? 1 : 0;

            $payload = $this->onlyExistingColumns('pembayaran_invoice_item', [
                'pembayaran_id' => $invoice->id,
                'registrasi_id' => $invoice->registrasi_id,
                'item_type' => 2,
                'source_type' => $sourceType,
                'source_detail_id' => $sourceDetailId > 0 ? $sourceDetailId : null,
                'jenis_transaksi' => (int) ($invoice->jenis_transaksi ?? 0),
                'nama_item' => $row['nama'] ?? $row['nama_item'] ?? $row['nama_treatment'] ?? 'Treatment',
                'satuan' => $row['unit'] ?? $row['satuan'] ?? 'x',
                'qty' => $qty,
                'harga' => $harga,
                'diskon_tipe' => $diskonTipe,
                'diskon_nilai' => $diskonNilai,
                'diskon_amount' => $diskonAmount + $promoDiskon,
                'diskon_referral' => $diskonReferral,
                'subtotal_before_diskon_subtotal' => $subtotal,
                'diskon_subtotal_amount' => 0,
                'subtotal_after_diskon_subtotal' => $subtotal,
                'subtotal' => $subtotal,
                'treatment_id' => $row['treatment_id'] ?? null,
                'treatment_toko_id' => $row['treatment_toko_id'] ?? null,
                'dokter_id' => $row['dokter_id'] ?? $invoice->dokter_id ?? null,
                'perawat_id' => $row['beautician_id'] ?? $row['perawat_id'] ?? null,
                'is_saran_dokter' => (int) ($row['is_saran_dokter'] ?? 0),
                'status' => 1,
                'is_delete' => 0,
                'created_by' => $this->username(),
                'updated_by' => $this->username(),
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $existing = $this->resolveRequestInvoiceItem($invoice, 2, $itemId, $sourceDetailId);

            if ($existing) {
                unset($payload['created_by'], $payload['created_at']);

                DB::table('pembayaran_invoice_item')
                    ->where('id', $existing->id)
                    ->update($payload);

                $activeItemIds[] = (int) $existing->id;
                continue;
            }

            $activeItemIds[] = (int) DB::table('pembayaran_invoice_item')->insertGetId($payload);
        }

        $this->softDeleteMissingRequestItems($invoice, 2, $activeItemIds);
    }

    protected function syncRequestProductItems(Request $request, PembayaranInvoice $invoice): void
    {
        $rows = collect((array) $request->input('penjualan_items', []))
            ->filter(fn ($row) => is_array($row))
            ->values();

        $activeItemIds = [];

        foreach ($rows as $row) {
            $itemId = (int) ($row['invoice_item_id'] ?? $row['id'] ?? 0);
            $sourceDetailId = (int) ($row['registrasi_penjualan_detail_id'] ?? $row['source_detail_id'] ?? 0);
            $qty = max((float) ($row['qty'] ?? $row['jumlah'] ?? 1), 1);
            $harga = (float) ($row['harga'] ?? $row['harga_jual'] ?? 0);
            $gross = $harga * $qty;

            $diskonTipe = $this->normalizeItemDiscountType(
                $row['diskon_type'] ?? $row['manual_diskon_type'] ?? $row['diskon_tipe'] ?? 0
            );
            $diskonNilai = (float) ($row['diskon'] ?? $row['manual_diskon'] ?? $row['diskon_nilai'] ?? 0);
            $diskonAmount = $this->calculateInvoiceItemDiscountAmount($gross, $diskonTipe, $diskonNilai);
            $diskonReferral = (float) ($row['diskon_referral'] ?? 0);
            $promoDiskon = (float) ($row['promo_diskon_amount'] ?? 0);
            $subtotal = max($gross - $diskonAmount - $diskonReferral - $promoDiskon, 0);

            $frekuensi = $row['frekuensi'] ?? $row['frekuensi_penggunaan'] ?? null;
            $waktuPakai = $row['waktu_pakai'] ?? $row['waktu_penggunaan'] ?? null;
            $instruksi = $row['penggunaan']
                ?? $row['instruksi_pemakaian']
                ?? $this->buildInstruksiPemakaian($frekuensi, $waktuPakai);

            $sourceType = $sourceDetailId > 0 ? 2 : 0;

            $payload = $this->onlyExistingColumns('pembayaran_invoice_item', [
                'pembayaran_id' => $invoice->id,
                'registrasi_id' => $invoice->registrasi_id,
                'item_type' => 3,
                'source_type' => $sourceType,
                'source_detail_id' => $sourceDetailId > 0 ? $sourceDetailId : null,
                'jenis_transaksi' => (int) ($invoice->jenis_transaksi ?? 0),
                'produk_id' => $row['produk_id'] ?? null,
                'produk_toko_id' => $row['produk_toko_id'] ?? null,
                'tempat_produk_id' => $row['tempat_produk_id'] ?? null,
                'stock_reservasi_id' => $row['stock_reservasi_id'] ?? null,
                'nama_item' => $row['nama'] ?? $row['nama_item'] ?? $row['nama_produk'] ?? 'Produk',
                'satuan' => $row['unit'] ?? $row['satuan'] ?? 'pcs',
                'qty' => $qty,
                'harga' => $harga,
                'diskon_tipe' => $diskonTipe,
                'diskon_nilai' => $diskonNilai,
                'diskon_amount' => $diskonAmount + $promoDiskon,
                'diskon_referral' => $diskonReferral,
                'subtotal_before_diskon_subtotal' => $subtotal,
                'diskon_subtotal_amount' => 0,
                'subtotal_after_diskon_subtotal' => $subtotal,
                'subtotal' => $subtotal,
                'frekuensi' => $frekuensi,
                'waktu_pakai' => $waktuPakai,
                'instruksi_pemakaian' => $instruksi,
                'status' => 1,
                'is_delete' => 0,
                'created_by' => $this->username(),
                'updated_by' => $this->username(),
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $existing = $this->resolveRequestInvoiceItem($invoice, 3, $itemId, $sourceDetailId);

            if ($existing) {
                unset($payload['created_by'], $payload['created_at']);

                DB::table('pembayaran_invoice_item')
                    ->where('id', $existing->id)
                    ->update($payload);

                $activeItemIds[] = (int) $existing->id;
                continue;
            }

            $activeItemIds[] = (int) DB::table('pembayaran_invoice_item')->insertGetId($payload);
        }

        $this->softDeleteMissingRequestItems($invoice, 3, $activeItemIds);
    }

    protected function hardResetRequestItems(PembayaranInvoice $invoice, int $itemType): void
    {
        DB::table('pembayaran_invoice_item')
            ->where('pembayaran_id', $invoice->id)
            ->where('item_type', $itemType)
            ->where(function ($q) {
                $q->whereNull('is_delete')->orWhere('is_delete', 0);
            })
            ->update($this->onlyExistingColumns('pembayaran_invoice_item', [
                'is_delete' => 1,
                'status' => 9,
                'updated_by' => $this->username(),
                'updated_at' => now(),
            ]));
    }

    protected function resolveRequestInvoiceItem(PembayaranInvoice $invoice, int $itemType, int $itemId = 0, int $sourceDetailId = 0)
    {
        $baseQuery = PembayaranInvoiceItem::query()
            ->where('pembayaran_id', $invoice->id)
            ->where('item_type', $itemType)
            ->lockForUpdate();

        if ($itemId > 0) {
            $item = (clone $baseQuery)->whereKey($itemId)->first();

            if ($item) {
                return $item;
            }
        }

        if ($sourceDetailId > 0) {
            return (clone $baseQuery)
                ->where('source_detail_id', $sourceDetailId)
                ->first();
        }

        return null;
    }

    protected function softDeleteMissingRequestItems(
        PembayaranInvoice $invoice,
        int $itemType,
        array $activeItemIds,
        array $activeSourceIds = []
    ): void {
        $query = DB::table('pembayaran_invoice_item')
            ->where('pembayaran_id', $invoice->id)
            ->where('item_type', $itemType)
            ->where(function ($q) {
                $q->whereNull('is_delete')->orWhere('is_delete', 0);
            })
            ->lockForUpdate();

        $activeItemIds = array_values(array_unique(array_filter(array_map('intval', $activeItemIds))));

        if (count($activeItemIds) > 0) {
            $query->whereNotIn('id', $activeItemIds);
        }

        $query->update($this->onlyExistingColumns('pembayaran_invoice_item', [
            'is_delete' => 1,
            'status' => 9,
            'updated_by' => $this->username(),
            'updated_at' => now(),
        ]));
    }

    protected function normalizeItemDiscountType($value): int
    {
        $text = strtolower(trim((string) $value));

        if (in_array($text, ['1', '%', 'percent', 'persen'], true)) {
            return 1;
        }

        if (in_array($text, ['2', 'rp', 'rupiah', 'nominal'], true)) {
            return 2;
        }

        return 0;
    }

    public function syncInvoiceItemsFromRegistrasi(PembayaranInvoice $invoice, RegistrasiKunjungan $registrasi): void
    {
        if (!$this->hasTable('pembayaran_invoice_item')) {
            return;
        }

        $registrasi->loadMissing(['treatmentDetails', 'penjualanDetails']);

        $activeKonsultasiSourceIds = $this->syncKonsultasiInvoiceItemFromRegistrasi($invoice, $registrasi);
        $activeTreatmentSourceIds = $this->syncTreatmentInvoiceItemsFromRegistrasi($invoice, $registrasi);
        $activePenjualanSourceIds = $this->syncPenjualanInvoiceItemsFromRegistrasi($invoice, $registrasi);
        $activeMarkerSourceIds = $this->syncAccurateMarkerItemsFromRegistrasi($invoice, $registrasi);

        $this->markRemovedInvoiceItems($invoice, 1, $activeKonsultasiSourceIds);
        $this->markRemovedInvoiceItems($invoice, 2, $activeTreatmentSourceIds);
        $this->markRemovedInvoiceItems($invoice, 3, $activePenjualanSourceIds);
        $this->markRemovedInvoiceItems($invoice, 5, $activeMarkerSourceIds);
    }

    protected function syncKonsultasiInvoiceItemFromRegistrasi(PembayaranInvoice $invoice, RegistrasiKunjungan $registrasi): array
    {
        $hasConsultation = $this->hasRegistrasiConsultation($registrasi);
        $sourceId = (int) ($registrasi->id ?? 0);

        if (!$hasConsultation || $sourceId <= 0) {
            return [];
        }

        $sourceCode = strtoupper((string) (
            $registrasi->konsultasi_source_code
                ?: $registrasi->channel_konsultasi
                ?: 'KONSULTASI'
        ));

        $mapping = $this->resolveAccurateMapping('konsultasi', $sourceCode);
        $subtotal = $this->resolveKonsultasiSubtotal($registrasi, $mapping, $sourceCode);

        if ($subtotal <= 0 && !$this->mappingShouldSendWhenZero($mapping)) {
            return [];
        }

        $payload = $this->onlyExistingColumns('pembayaran_invoice_item', [
            'pembayaran_id' => $invoice->id,
            'registrasi_id' => $registrasi->id,
            'item_type' => 1,
            'source_type' => 3,
            'source_detail_id' => $sourceId,
            'jenis_transaksi' => (int) ($invoice->jenis_transaksi ?? 0),
            'accurate_mapping_id' => $mapping->id ?? null,
            'accurate_source_type' => $mapping->source_type ?? 'konsultasi',
            'accurate_source_code' => $mapping->source_code ?? $sourceCode,
            'kode_accurate_snapshot' => $mapping->kode_accurate ?? null,
            'nama_accurate_snapshot' => $mapping->nama_accurate ?? null,
            'is_send_to_accurate' => $mapping ? (int) ($mapping->is_send_to_accurate ?? 0) : 0,
            'send_when_zero' => $mapping ? (int) ($mapping->send_when_zero ?? 0) : 0,
            'nama_item' => $registrasi->konsultasi_source_name ?: ($mapping->source_name ?? 'Konsultasi'),
            'satuan' => 'x',
            'qty' => 1,
            'harga' => $subtotal,
            'diskon_tipe' => 0,
            'diskon_nilai' => 0,
            'diskon_amount' => 0,
            'diskon_referral' => 0,
            'subtotal_before_diskon_subtotal' => $subtotal,
            'diskon_subtotal_amount' => 0,
            'subtotal_after_diskon_subtotal' => $subtotal,
            'subtotal' => $subtotal,
            'dokter_id' => $registrasi->dokter_awal_id ?? null,
            'perawat_id' => $registrasi->perawat_awal_id ?? null,
            'status' => 1,
            'is_delete' => 0,
            'created_by' => $this->username(),
            'updated_by' => $this->username(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->upsertInvoiceItemBySource($invoice->id, 1, 3, $sourceId, $payload);
        $this->deactivateOtherConsultationItems($invoice->id, 3, $sourceId);

        return [$sourceId];
    }

    protected function resolveKonsultasiSubtotal(RegistrasiKunjungan $registrasi, ?object $mapping, string $sourceCode): float
    {
        if ($this->isFreeConsultationByRule($registrasi, $sourceCode)) {
            return 0.0;
        }

        $subtotal = (float) ($registrasi->total_konsultasi ?? 0);

        if ($subtotal > 0) {
            return $subtotal;
        }

        if ($mapping && (int) ($mapping->is_billable ?? 0) === 1) {
            return max((float) ($mapping->default_harga ?? 0), 0);
        }

        return 0.0;
    }

    protected function isFreeConsultationByRule(RegistrasiKunjungan $registrasi, string $sourceCode): bool
    {
        $sourceCode = strtoupper(trim((string) $sourceCode));
        $channel = strtolower(trim((string) ($registrasi->channel_konsultasi ?? '')));
        $ruleBiaya = (int) ($registrasi->rule_biaya_konsultasi ?? 0);

        if (in_array($ruleBiaya, [2, 3], true)) {
            return true;
        }

        if ($sourceCode === 'KONSULTASI_ONLINE' || str_contains($sourceCode, 'ONLINE')) {
            return true;
        }

        if ((int) ($registrasi->is_konsultasi_online ?? 0) === 1) {
            return true;
        }

        return in_array($channel, ['2', 'online'], true);
    }

    protected function deactivateOtherConsultationItems(int $invoiceId, int $activeSourceType, int $activeSourceDetailId): void
    {
        DB::table('pembayaran_invoice_item')
            ->where('pembayaran_id', $invoiceId)
            ->where('item_type', 1)
            ->where(function ($q) {
                $q->whereNull('is_delete')->orWhere('is_delete', 0);
            })
            ->where(function ($q) use ($activeSourceType, $activeSourceDetailId) {
                $q->where('source_type', '!=', $activeSourceType)
                    ->orWhere('source_detail_id', '!=', $activeSourceDetailId)
                    ->orWhereNull('source_type')
                    ->orWhereNull('source_detail_id');
            })
            ->update($this->onlyExistingColumns('pembayaran_invoice_item', [
                'is_delete' => 1,
                'status' => 9,
                'updated_by' => $this->username(),
                'updated_at' => now(),
            ]));
    }

    protected function syncAccurateMarkerItemsFromRegistrasi(PembayaranInvoice $invoice, RegistrasiKunjungan $registrasi): array
    {
        $activeIds = [];

        if ((int) ($registrasi->is_pembelian_online ?? 0) === 1) {
            $sourceId = (int) ($registrasi->id ?? 0);
            $mapping = $this->resolveAccurateMapping('marker', 'PEMBELIAN_ONLINE')
                ?: $this->resolveAccurateMapping('pembelian_online', 'PEMBELIAN_ONLINE');

            if ($sourceId > 0 && $this->mappingShouldSendWhenZero($mapping)) {
                $activeIds[] = $sourceId;

                $payload = $this->onlyExistingColumns('pembayaran_invoice_item', [
                    'pembayaran_id' => $invoice->id,
                    'registrasi_id' => $registrasi->id,
                    'item_type' => 5,
                    'source_type' => 4,
                    'source_detail_id' => $sourceId,
                    'jenis_transaksi' => (int) ($invoice->jenis_transaksi ?? 0),
                    'accurate_mapping_id' => $mapping->id ?? null,
                    'accurate_source_type' => $mapping->source_type ?? 'marker',
                    'accurate_source_code' => $mapping->source_code ?? 'PEMBELIAN_ONLINE',
                    'kode_accurate_snapshot' => $mapping->kode_accurate ?? null,
                    'nama_accurate_snapshot' => $mapping->nama_accurate ?? null,
                    'is_send_to_accurate' => $mapping ? (int) ($mapping->is_send_to_accurate ?? 0) : 0,
                    'send_when_zero' => $mapping ? (int) ($mapping->send_when_zero ?? 0) : 0,
                    'nama_item' => $mapping->source_name ?? 'Pembelian Online',
                    'satuan' => 'x',
                    'qty' => 1,
                    'harga' => 0,
                    'subtotal_before_diskon_subtotal' => 0,
                    'diskon_subtotal_amount' => 0,
                    'subtotal_after_diskon_subtotal' => 0,
                    'subtotal' => 0,
                    'status' => 1,
                    'is_delete' => 0,
                    'created_by' => $this->username(),
                    'updated_by' => $this->username(),
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);

                $this->upsertInvoiceItemBySource($invoice->id, 5, 4, $sourceId, $payload);
            }
        }

        return $activeIds;
    }

    protected function resolveTreatmentPrice($detail, RegistrasiKunjungan $registrasi): float
    {
        $candidateFields = [
            'harga',
            'harga_treatment',
            'tarif',
            'harga_terendah',
            'price',
        ];

        foreach ($candidateFields as $field) {
            $value = (float) ($detail->{$field} ?? 0);

            if ($value > 0) {
                return $value;
            }
        }

        $treatmentTokoId = (int) ($detail->treatment_toko_id ?? 0);
        $treatmentId = (int) ($detail->treatment_id ?? 0);
        $tokoId = (int) ($registrasi->toko_id ?? 0);

        if ($this->hasTable('master_treatment_toko')) {
            $query = DB::table('master_treatment_toko')
                ->where(function ($q) {
                    $q->whereNull('is_delete')->orWhere('is_delete', 0);
                });

            if ($treatmentTokoId > 0) {
                $row = (clone $query)
                    ->where('id', $treatmentTokoId)
                    ->first(['tarif', 'harga_terendah']);

                $price = $this->pickPositiveAmount([
                    $row->tarif ?? 0,
                    $row->harga_terendah ?? 0,
                ]);

                if ($price > 0) {
                    return $price;
                }
            }

            if ($treatmentId > 0 && $tokoId > 0) {
                $row = (clone $query)
                    ->where('treatment_id', $treatmentId)
                    ->where('toko_id', $tokoId)
                    ->first(['tarif', 'harga_terendah']);

                $price = $this->pickPositiveAmount([
                    $row->tarif ?? 0,
                    $row->harga_terendah ?? 0,
                ]);

                if ($price > 0) {
                    return $price;
                }
            }
        }

        $qty = max((float) ($detail->jumlah ?? $detail->qty ?? 1), 1);
        $subtotal = (float) ($detail->total ?? $detail->subtotal ?? 0);

        return $subtotal > 0 ? round($subtotal / $qty, 2) : 0.0;
    }

    protected function pickPositiveAmount(array $values): float
    {
        foreach ($values as $value) {
            $amount = (float) $value;

            if ($amount > 0) {
                return $amount;
            }
        }

        return 0.0;
    }

    protected function syncTreatmentInvoiceItemsFromRegistrasi(PembayaranInvoice $invoice, RegistrasiKunjungan $registrasi): array
    {
        $activeIds = [];
        $details = collect($registrasi->treatmentDetails ?? []);

        foreach ($details as $detail) {
            if ((int) ($detail->is_delete ?? 0) === 1 || (int) ($detail->status ?? 0) === 9) {
                continue;
            }

            $sourceId = (int) ($detail->id ?? 0);

            if ($sourceId <= 0) {
                continue;
            }

            $activeIds[] = $sourceId;

            $qty = max((float) ($detail->jumlah ?? $detail->qty ?? 1), 1);
            $harga = $this->resolveTreatmentPrice($detail, $registrasi);
            $gross = $harga * $qty;
            $rawSubtotal = (float) ($detail->total ?? $detail->subtotal ?? 0);
            $subtotal = $rawSubtotal > 0 ? $rawSubtotal : $gross;

            $payload = $this->onlyExistingColumns('pembayaran_invoice_item', [
                'pembayaran_id' => $invoice->id,
                'registrasi_id' => $registrasi->id,
                'item_type' => 2,
                'source_type' => 1,
                'source_detail_id' => $sourceId,
                'jenis_transaksi' => (int) ($invoice->jenis_transaksi ?? 0),
                'deposit_treatment_id' => $detail->deposit_treatment_id ?? null,
                'deposit_claim_id' => $detail->deposit_claim_id ?? null,
                'treatment_id' => $detail->treatment_id ?? null,
                'treatment_toko_id' => $detail->treatment_toko_id ?? null,
                'nama_item' => $detail->nama_treatment
                    ?? $detail->treatment?->nama
                    ?? $detail->treatmentToko?->nama_treatment
                    ?? 'Treatment',
                'satuan' => $detail->satuan ?? 'x',
                'qty' => $qty,
                'harga' => $harga,
                'diskon_tipe' => (int) ($detail->diskon_tipe ?? 0),
                'diskon_nilai' => (float) ($detail->diskon_nilai ?? 0),
                'diskon_amount' => (float) ($detail->diskon_amount ?? 0),
                'diskon_referral' => (float) ($detail->diskon_referral ?? 0),
                'subtotal_before_diskon_subtotal' => $subtotal,
                'diskon_subtotal_amount' => 0,
                'subtotal_after_diskon_subtotal' => $subtotal,
                'subtotal' => $subtotal,
                'dokter_id' => $registrasi->dokter_awal_id ?? null,
                'perawat_id' => $registrasi->perawat_awal_id ?? null,
                'is_saran_dokter' => (int) ($detail->is_saran_dokter ?? 0),
                'status' => 1,
                'is_delete' => 0,
                'created_by' => $this->username(),
                'updated_by' => $this->username(),
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $this->upsertInvoiceItemBySource($invoice->id, 2, 1, $sourceId, $payload);
        }

        return $activeIds;
    }

    protected function syncPenjualanInvoiceItemsFromRegistrasi(PembayaranInvoice $invoice, RegistrasiKunjungan $registrasi): array
    {
        $activeIds = [];
        $details = collect($registrasi->penjualanDetails ?? []);

        foreach ($details as $detail) {
            if ((int) ($detail->is_delete ?? 0) === 1 || (int) ($detail->status ?? 0) === 9) {
                continue;
            }

            $sourceId = (int) ($detail->id ?? 0);

            if ($sourceId <= 0) {
                continue;
            }

            $activeIds[] = $sourceId;

            $qty = max((float) ($detail->jumlah ?? $detail->qty ?? 1), 1);
            $harga = (float) ($detail->harga ?? $detail->harga_jual ?? 0);
            $gross = $harga * $qty;

            $diskonTipe = (int) ($detail->diskon_tipe ?? 0);
            $diskonNilai = (float) ($detail->diskon_nilai ?? 0);
            $diskonAmount = $this->calculateInvoiceItemDiscountAmount($gross, $diskonTipe, $diskonNilai);
            $diskonReferral = (float) ($detail->diskon_referral ?? 0);
            $subtotal = (float) ($detail->subtotal ?? max($gross - $diskonAmount - $diskonReferral, 0));

            $frekuensi = $detail->frekuensi_penggunaan ?? $detail->frekuensi ?? null;
            $waktuPakai = $detail->waktu_penggunaan ?? $detail->waktu_pakai ?? null;
            $instruksi = $detail->instruksi_pemakaian
                ?? $detail->penggunaan
                ?? $this->buildInstruksiPemakaian($frekuensi, $waktuPakai);

            $payload = $this->onlyExistingColumns('pembayaran_invoice_item', [
                'pembayaran_id' => $invoice->id,
                'registrasi_id' => $registrasi->id,
                'item_type' => 3,
                'source_type' => 2,
                'source_detail_id' => $sourceId,
                'jenis_transaksi' => (int) ($invoice->jenis_transaksi ?? 0),
                'produk_id' => $detail->produk_id ?? null,
                'produk_toko_id' => $detail->produk_toko_id ?? null,
                'tempat_produk_id' => $detail->tempat_produk_id ?? null,
                'stock_reservasi_id' => $detail->stock_reservasi_id ?? null,
                'nama_item' => $detail->nama_produk
                    ?? $detail->produk?->nama
                    ?? $detail->produkToko?->nama_produk
                    ?? 'Produk',
                'satuan' => $detail->unit ?? $detail->satuan ?? $detail->produk?->satuan_nama ?? 'pcs',
                'qty' => $qty,
                'harga' => $harga,
                'diskon_tipe' => $diskonTipe,
                'diskon_nilai' => $diskonNilai,
                'diskon_amount' => $diskonAmount,
                'diskon_referral' => $diskonReferral,
                'subtotal_before_diskon_subtotal' => $subtotal,
                'diskon_subtotal_amount' => 0,
                'subtotal_after_diskon_subtotal' => $subtotal,
                'subtotal' => $subtotal,
                'dokter_id' => (int) ($detail->is_saran_dokter ?? 0) === 1
                    ? ($registrasi->dokter_awal_id ?? null)
                    : null,
                'is_saran_dokter' => (int) ($detail->is_saran_dokter ?? 0),
                'frekuensi' => $frekuensi,
                'waktu_pakai' => $waktuPakai,
                'instruksi_pemakaian' => $instruksi,
                'status' => 1,
                'is_delete' => 0,
                'created_by' => $this->username(),
                'updated_by' => $this->username(),
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $this->upsertInvoiceItemBySource($invoice->id, 3, 2, $sourceId, $payload);
        }

        return $activeIds;
    }

    protected function upsertInvoiceItemBySource(
        int $invoiceId,
        int $itemType,
        int $sourceType,
        int $sourceDetailId,
        array $payload
    ): void {
        $existingId = DB::table('pembayaran_invoice_item')
            ->where('pembayaran_id', $invoiceId)
            ->where('item_type', $itemType)
            ->where('source_type', $sourceType)
            ->where('source_detail_id', $sourceDetailId)
            ->where(function ($q) {
                $q->whereNull('is_delete')->orWhere('is_delete', 0);
            })
            ->lockForUpdate()
            ->value('id');

        if ($existingId) {
            unset($payload['created_by'], $payload['created_at']);

            DB::table('pembayaran_invoice_item')
                ->where('id', $existingId)
                ->update($payload);

            return;
        }

        DB::table('pembayaran_invoice_item')->insert($payload);
    }

    protected function markRemovedInvoiceItems(PembayaranInvoice $invoice, int $itemType, array $activeSourceIds): void
    {
        $query = DB::table('pembayaran_invoice_item')
            ->where('pembayaran_id', $invoice->id)
            ->where('item_type', $itemType)
            ->where(function ($q) {
                $q->whereNull('is_delete')->orWhere('is_delete', 0);
            });

        if (!empty($activeSourceIds)) {
            $query->whereNotIn('source_detail_id', $activeSourceIds);
        }

        $query->update($this->onlyExistingColumns('pembayaran_invoice_item', [
            'is_delete' => 1,
            'status' => 9,
            'updated_by' => $this->username(),
            'updated_at' => now(),
        ]));
    }

    protected function invoiceHasActiveTreatment(PembayaranInvoice $invoice): bool
    {
        if (!$this->hasTable('pembayaran_invoice_item')) {
            return false;
        }

        return DB::table('pembayaran_invoice_item')
            ->where('pembayaran_id', $invoice->id)
            ->where('item_type', 2)
            ->where(function ($q) {
                $q->whereNull('is_delete')->orWhere('is_delete', 0);
            })
            ->exists();
    }

    protected function zeroConsultationChargeWhenInvoiceHasTreatment(PembayaranInvoice $invoice): void
    {
        if (!$this->hasTable('pembayaran_invoice_item')) {
            return;
        }

        if (!$this->invoiceHasActiveTreatment($invoice)) {
            return;
        }

        DB::table('pembayaran_invoice_item')
            ->where('pembayaran_id', $invoice->id)
            ->where('item_type', 1)
            ->where(function ($q) {
                $q->whereNull('is_delete')->orWhere('is_delete', 0);
            })
            ->update($this->onlyExistingColumns('pembayaran_invoice_item', [
                'harga' => 0,
                'diskon_tipe' => 0,
                'diskon_nilai' => 0,
                'diskon_amount' => 0,
                'diskon_referral' => 0,
                'subtotal_before_diskon_subtotal' => 0,
                'diskon_subtotal_amount' => 0,
                'subtotal_after_diskon_subtotal' => 0,
                'subtotal' => 0,
                'updated_by' => $this->username(),
                'updated_at' => now(),
            ]));
    }

    public function refreshInvoiceTotalsFromItems(PembayaranInvoice $invoice): void
    {
        if (!$this->hasTable('pembayaran_invoice_item')) {
            return;
        }

        $hasActiveTreatment = $this->invoiceHasActiveTreatment($invoice);

        if ($hasActiveTreatment) {
            $this->zeroConsultationChargeWhenInvoiceHasTreatment($invoice);
        }

        $totals = DB::table('pembayaran_invoice_item')
            ->selectRaw("SUM(CASE WHEN item_type = 2 THEN subtotal ELSE 0 END) as treatment_total")
            ->selectRaw("SUM(CASE WHEN item_type = 3 THEN subtotal ELSE 0 END) as produk_total")
            ->selectRaw("SUM(CASE WHEN item_type = 1 THEN subtotal ELSE 0 END) as konsultasi_total")
            ->where('pembayaran_id', $invoice->id)
            ->where(function ($q) {
                $q->whereNull('is_delete')->orWhere('is_delete', 0);
            })
            ->first();

        $subtotalTreatment = (float) ($totals->treatment_total ?? 0);
        $subtotalProduk = (float) ($totals->produk_total ?? 0);
        $subtotalKonsultasi = $hasActiveTreatment ? 0.0 : (float) ($totals->konsultasi_total ?? 0);

        $subtotal = $subtotalTreatment + $subtotalProduk + $subtotalKonsultasi;
        $prorationBase = $subtotalTreatment + $subtotalProduk;

        $diskonSubtotal = min(
            max((float) ($invoice->diskon_subtotal_amount ?? $invoice->diskon_subtotal ?? 0), 0),
            max($prorationBase, 0)
        );

        $this->prorateSubtotalDiscountToItems($invoice, $diskonSubtotal);

        $diskonPromo = (float) ($invoice->total_promo ?? $invoice->diskon_promo ?? 0);
        $diskonMember = (float) ($invoice->diskon_member_amount ?? 0);
        $grandTotal = max($subtotal - $diskonSubtotal - $diskonPromo - $diskonMember, 0);

        $invoice->update($this->onlyExistingColumns('pembayaran_invoice', [
            'subtotal_obat' => $subtotalProduk,
            'subtotal_produk' => $subtotalProduk,
            'subtotal_treatment' => $subtotalTreatment,
            'subtotal_konsultasi' => $subtotalKonsultasi,
            'subtotal' => $subtotal,
            'diskon_subtotal_amount' => $diskonSubtotal,
            'grand_total' => $grandTotal,
            'sisa_tagihan' => max($grandTotal - (float) ($invoice->total_bayar ?? 0), 0),
            'updated_by' => $this->username(),
            'updated_at' => now(),
        ]));

        $invoice->forceFill([
            'subtotal_produk' => $subtotalProduk,
            'subtotal_treatment' => $subtotalTreatment,
            'subtotal_konsultasi' => $subtotalKonsultasi,
            'subtotal' => $subtotal,
            'diskon_subtotal_amount' => $diskonSubtotal,
            'grand_total' => $grandTotal,
        ]);
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

    protected function resolveAccurateMapping(string $sourceType, string $sourceCode): ?object
    {
        if (!$this->hasTable('master_accurate_item_mapping')) {
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
        return $mapping
            && (int) ($mapping->is_send_to_accurate ?? 0) === 1
            && (int) ($mapping->send_when_zero ?? 0) === 1;
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

    protected function prorateSubtotalDiscountToItems(PembayaranInvoice $invoice, float $diskonSubtotal): void
    {
        if (!$this->hasTable('pembayaran_invoice_item')) {
            return;
        }

        $hasProrataAmount = $this->hasColumn('pembayaran_invoice_item', 'diskon_subtotal_amount');
        $hasBeforeColumn = $this->hasColumn('pembayaran_invoice_item', 'subtotal_before_diskon_subtotal');
        $hasAfterColumn = $this->hasColumn('pembayaran_invoice_item', 'subtotal_after_diskon_subtotal');

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

        if ($items->isEmpty()) {
            return;
        }

        $baseSubtotal = (float) $items->sum(fn ($item) => (float) ($item->subtotal ?? 0));
        $diskonSubtotal = min(max($diskonSubtotal, 0), max($baseSubtotal, 0));

        if ($baseSubtotal <= 0 || $diskonSubtotal <= 0) {
            $payload = [
                'updated_by' => $this->username(),
                'updated_at' => now(),
            ];

            if ($hasProrataAmount) {
                $payload['diskon_subtotal_amount'] = 0;
            }

            if ($hasBeforeColumn) {
                $payload['subtotal_before_diskon_subtotal'] = DB::raw('subtotal');
            }

            if ($hasAfterColumn) {
                $payload['subtotal_after_diskon_subtotal'] = DB::raw('subtotal');
            }

            DB::table('pembayaran_invoice_item')
                ->where('pembayaran_id', $invoice->id)
                ->whereIn('item_type', [2, 3])
                ->where(function ($q) {
                    $q->whereNull('is_delete')->orWhere('is_delete', 0);
                })
                ->update($this->onlyExistingColumns('pembayaran_invoice_item', $payload));

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

    protected function onlyExistingColumns(string $table, array $data): array
    {
        $columns = $this->tableColumns($table);

        if (empty($columns)) {
            return [];
        }

        return collect($data)
            ->filter(fn ($value, $key) => isset($columns[$key]))
            ->all();
    }

    protected function hasTable(string $table): bool
    {
        if (!array_key_exists($table, $this->tableExistsCache)) {
            $this->tableExistsCache[$table] = Schema::hasTable($table);
        }

        return $this->tableExistsCache[$table];
    }

    protected function hasColumn(string $table, string $column): bool
    {
        $columns = $this->tableColumns($table);

        return isset($columns[$column]);
    }

    protected function tableColumns(string $table): array
    {
        if (!$this->hasTable($table)) {
            return [];
        }

        if (!array_key_exists($table, $this->tableColumnCache)) {
            $this->tableColumnCache[$table] = array_fill_keys(Schema::getColumnListing($table), true);
        }

        return $this->tableColumnCache[$table];
    }

    protected function username(): string
    {
        if ($this->usernameCache !== null) {
            return $this->usernameCache;
        }

        return $this->usernameCache = (string) (auth()->user()->username ?? auth()->user()->name ?? 'system');
    }
}