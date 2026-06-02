<?php

namespace App\Services\Pembayaran;

use App\Models\Pembayaran\PembayaranInvoice;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

class PaymentPatientService
{
    public function updatePhoneFromRequest(Request $request, PembayaranInvoice $invoice): void
    {
        if (!$request->boolean('update_pasien_phone')) {
            return;
        }

        if (!Schema::hasTable('pasien') || empty($invoice->pasien_id)) {
            return;
        }

        $noHp = $this->normalizeIndonesianPhone($request->input('pasien_no_hp_update'));
        $noWa = $this->normalizeIndonesianPhone($request->input('pasien_no_wa_update') ?: $request->input('pasien_no_hp_update'));
        $noTelp = $this->normalizeIndonesianPhone($request->input('pasien_no_telp_update'));

        if (!$noHp && !$noWa && !$noTelp) {
            throw ValidationException::withMessages([
                'pasien_no_hp_update' => 'Nomor telepon/HP/WA tidak boleh kosong jika memilih update nomor pasien.',
            ]);
        }

        $payload = [];
        if ($noHp) {
            $payload['no_hp'] = $noHp;
        }
        if ($noWa) {
            $payload['no_wa'] = $noWa;
        }
        if ($noTelp) {
            $payload['no_telp'] = $noTelp;
        }

        $updaterId = $this->resolvePasienUpdaterId();
        if ($updaterId !== null && Schema::hasColumn('pasien', 'updated_by')) {
            $payload['updated_by'] = $updaterId;
        }

        if (Schema::hasColumn('pasien', 'updated_at')) {
            $payload['updated_at'] = now();
        }

        DB::table('pasien')
            ->where('id', $invoice->pasien_id)
            ->lockForUpdate()
            ->update($this->onlyExistingColumns('pasien', $payload));
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

    protected function onlyExistingColumns(string $table, array $data): array
    {
        return collect($data)
            ->filter(fn ($value, $key) => Schema::hasColumn($table, $key))
            ->all();
    }
}
