<?php

namespace App\Services\Registrasi;

use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class TreatmentPerformerService
{
    private const ALLOWED_JOB_CODES = ['BC', 'NS'];

    public function options(int $tokoId, ?string $tanggal = null): Collection
    {
        $date = CarbonImmutable::parse($tanggal ?: now())->toDateString();

        return $this->baseQuery($tokoId, $date)
            ->orderBy('j.sort_order')
            ->orderBy('k.sort_order')
            ->orderBy('k.nama')
            ->get([
                'k.id',
                'k.kode',
                'k.nama',
                'k.jabatan_id',
                'j.kode_jabatan',
                'j.nama_jabatan',
                'j.sort_order as jabatan_sort_order',
                'k.sort_order as karyawan_sort_order',
                'mkp.toko_id',
            ])
            ->map(static function ($row): array {
                $jobName = trim((string) ($row->nama_jabatan ?? ''));
                $jobCode = strtoupper(trim((string) ($row->kode_jabatan ?? '')));
                $name = trim((string) ($row->nama ?? ''));

                return [
                    'id' => (int) $row->id,
                    'value' => (int) $row->id,
                    'kode' => $row->kode,
                    'nama' => $name,
                    'title' => $jobName !== '' ? "{$name} - {$jobName}" : $name,
                    'jabatan_id' => (int) $row->jabatan_id,
                    'kode_jabatan' => $jobCode,
                    'nama_jabatan' => $jobName,
                    'toko_id' => (int) $row->toko_id,
                ];
            })
            ->values();
    }

    /**
     * Merge perawat_id from the raw request into normalized treatment rows,
     * then validate that each selected treatment has an active Nurse/Beautician
     * placement at the selected branch and service date.
     */
    public function mergeRegistrationItems(
        array $normalizedItems,
        array $rawItems,
        int $tokoId,
        string $tanggal
    ): array {
        $usedRawIndexes = [];

        foreach ($normalizedItems as $index => &$item) {
            $rawIndex = $this->findMatchingRawIndex(
                $item,
                $rawItems,
                $usedRawIndexes,
                $index
            );

            $rawItem = $rawIndex !== null ? ($rawItems[$rawIndex] ?? []) : [];
            if ($rawIndex !== null) {
                $usedRawIndexes[$rawIndex] = true;
            }

            $performer = $this->assertValidPerformer(
                $rawItem['perawat_id'] ?? $item['perawat_id'] ?? null,
                $tokoId,
                $tanggal,
                "treatment.items.{$index}.perawat_id"
            );

            $item['perawat_id'] = (int) $performer->id;
            $item['perawat_nama'] = (string) $performer->nama;
            $item['perawat_jabatan_kode'] = (string) $performer->kode_jabatan;
            $item['perawat_jabatan_nama'] = (string) $performer->nama_jabatan;
        }
        unset($item);

        return $normalizedItems;
    }

    /**
     * Validate doctor-stage treatment rows. The controller can keep using the
     * same payload after this method returns because no derived field is trusted
     * from the frontend; only perawat_id is persisted.
     */
    public function validateDoctorItems(
        array $items,
        int $tokoId,
        string $tanggal
    ): void {
        foreach ($items as $index => $item) {
            if (!$this->isSelectedTreatment($item)) {
                continue;
            }

            $this->assertValidPerformer(
                $item['perawat_id'] ?? null,
                $tokoId,
                $tanggal,
                "treatment_items.{$index}.perawat_id"
            );
        }
    }

    public function assertValidPerformer(
        mixed $perawatId,
        int $tokoId,
        string $tanggal,
        string $field = 'perawat_id'
    ): object {
        $id = filter_var($perawatId, FILTER_VALIDATE_INT, [
            'options' => ['min_range' => 1],
        ]);

        if (!$id) {
            throw ValidationException::withMessages([
                $field => ['Nurse / Beautician wajib dipilih untuk treatment ini.'],
            ]);
        }

        $date = CarbonImmutable::parse($tanggal)->toDateString();
        $performer = $this->baseQuery($tokoId, $date)
            ->where('k.id', (int) $id)
            ->first([
                'k.id',
                'k.nama',
                'j.kode_jabatan',
                'j.nama_jabatan',
            ]);

        if (!$performer) {
            throw ValidationException::withMessages([
                $field => [
                    'Nurse / Beautician tidak aktif, bukan jabatan BC/NS, atau tidak ditempatkan pada cabang dan tanggal layanan ini.',
                ],
            ]);
        }

        return $performer;
    }

    private function baseQuery(int $tokoId, string $tanggal)
    {
        return DB::table('master_karyawan as k')
            ->join('master_jabatan as j', 'j.id', '=', 'k.jabatan_id')
            ->join('master_karyawan_penempatan as mkp', 'mkp.karyawan_id', '=', 'k.id')
            ->where('mkp.toko_id', $tokoId)
            ->whereIn(DB::raw('UPPER(j.kode_jabatan)'), self::ALLOWED_JOB_CODES)
            ->where(function ($query) {
                $query->where('k.is_delete', 0)->orWhereNull('k.is_delete');
            })
            ->where(function ($query) {
                $query->where('j.is_delete', 0)->orWhereNull('j.is_delete');
            })
            ->where(function ($query) {
                $query->where('mkp.is_delete', 0)->orWhereNull('mkp.is_delete');
            })
            ->where(function ($query) use ($tanggal) {
                $query->whereNull('mkp.tanggal_mulai')
                    ->orWhereDate('mkp.tanggal_mulai', '<=', $tanggal);
            })
            ->where(function ($query) use ($tanggal) {
                $query->whereNull('mkp.tanggal_selesai')
                    ->orWhereDate('mkp.tanggal_selesai', '>=', $tanggal);
            })
            ->distinct();
    }

    private function findMatchingRawIndex(
        array $normalizedItem,
        array $rawItems,
        array $usedRawIndexes,
        int $fallbackIndex
    ): ?int {
        $normalizedTokoId = $this->firstPositiveInt([
            $normalizedItem['treatment_toko_id'] ?? null,
            $normalizedItem['master_treatment_toko_id'] ?? null,
            $normalizedItem['tindakan_toko_id'] ?? null,
        ]);
        $normalizedTreatmentId = $this->firstPositiveInt([
            $normalizedItem['treatment_id'] ?? null,
            $normalizedItem['tindakan_id'] ?? null,
            $normalizedItem['master_treatment_id'] ?? null,
        ]);

        foreach ($rawItems as $rawIndex => $rawItem) {
            if (isset($usedRawIndexes[$rawIndex]) || !is_array($rawItem)) {
                continue;
            }

            $rawTokoId = $this->firstPositiveInt([
                $rawItem['treatment_toko_id'] ?? null,
                $rawItem['master_treatment_toko_id'] ?? null,
                $rawItem['tindakan_toko_id'] ?? null,
                $rawItem['toko_treatment_id'] ?? null,
            ]);
            $rawTreatmentId = $this->firstPositiveInt([
                $rawItem['treatment_id'] ?? null,
                $rawItem['tindakan_id'] ?? null,
                $rawItem['master_treatment_id'] ?? null,
            ]);

            if ($normalizedTokoId && $rawTokoId === $normalizedTokoId) {
                return (int) $rawIndex;
            }

            if (!$normalizedTokoId && $normalizedTreatmentId && $rawTreatmentId === $normalizedTreatmentId) {
                return (int) $rawIndex;
            }
        }

        if (
            array_key_exists($fallbackIndex, $rawItems)
            && !isset($usedRawIndexes[$fallbackIndex])
            && is_array($rawItems[$fallbackIndex])
        ) {
            return $fallbackIndex;
        }

        return null;
    }

    private function isSelectedTreatment(array $item): bool
    {
        return $this->firstPositiveInt([
            $item['treatment_toko_id'] ?? null,
            $item['master_treatment_toko_id'] ?? null,
            $item['treatment_id'] ?? null,
            $item['tindakan_id'] ?? null,
            $item['master_treatment_id'] ?? null,
        ]) !== null;
    }

    private function firstPositiveInt(array $values): ?int
    {
        foreach ($values as $value) {
            $number = filter_var($value, FILTER_VALIDATE_INT, [
                'options' => ['min_range' => 1],
            ]);

            if ($number) {
                return (int) $number;
            }
        }

        return null;
    }
}
