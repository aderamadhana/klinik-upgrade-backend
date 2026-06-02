<?php

namespace App\Services\Pembayaran;

use App\Models\Pembayaran\PembayaranInvoice;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class PaymentSubtotalDiscountService
{
    public function applyFromRequest(Request $request, PembayaranInvoice $invoice): void
    {
        if (!$request->filled('diskon_subtotal_nilai')) {
            $this->prorateToItems(
                $invoice,
                (float) ($invoice->diskon_subtotal_amount ?? 0)
            );
            return;
        }

        $baseSubtotal = $this->getProrationBase($invoice);
        $tipe = $request->input('diskon_subtotal_tipe', 0);
        $nilai = (float) $request->input('diskon_subtotal_nilai', 0);
        $tipeValue = $this->normalizeType($tipe);

        if ($nilai <= 0 || $baseSubtotal <= 0) {
            $amount = 0;
        } elseif ($tipeValue === 1) {
            $amount = min(round(($baseSubtotal * $nilai) / 100, 2), $baseSubtotal);
        } else {
            $amount = min($nilai, $baseSubtotal);
        }

        $invoice->update($this->onlyExistingColumns('pembayaran_invoice', [
            'diskon_subtotal_tipe' => $amount > 0 ? $tipeValue : 0,
            'diskon_subtotal_nilai' => $amount > 0 ? $nilai : 0,
            'diskon_subtotal_amount' => $amount,
            'updated_by' => $this->username(),
            'updated_at' => now(),
        ]));

        $invoice->forceFill([
            'diskon_subtotal_tipe' => $amount > 0 ? $tipeValue : 0,
            'diskon_subtotal_nilai' => $amount > 0 ? $nilai : 0,
            'diskon_subtotal_amount' => $amount,
        ]);

        $this->prorateToItems($invoice, $amount);
    }

    public function prorateToItems(PembayaranInvoice $invoice, float $diskonSubtotal): void
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

    protected function getProrationBase(PembayaranInvoice $invoice): float
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

    protected function normalizeType($value): int
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

    protected function onlyExistingColumns(string $table, array $data): array
    {
        return collect($data)
            ->filter(fn ($value, $key) => Schema::hasColumn($table, $key))
            ->all();
    }

    protected function username(): string
    {
        return (string) (auth()->user()->username ?? auth()->user()->name ?? 'system');
    }
}
