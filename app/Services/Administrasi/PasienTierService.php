<?php

namespace App\Services\Administrasi;

use App\Models\Master\MasterMemberTier;
use App\Models\Pasien;
use App\Models\PasienMember;
use App\Models\PasienMemberTierHistory;
use Carbon\Carbon;
use DomainException;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;

class PasienTierService
{
    public function detail(int $pasienId): array
    {
        $this->assertSchemaReady();
        $pasien = $this->findPatient($pasienId);
        $tiers = $this->activeTiers();
        $member = PasienMember::query()
            ->notDeleted()
            ->with(['tier', 'tokoDaftar'])
            ->where('pasien_id', $pasien->id)
            ->first();

        return $this->buildPayload($pasien, $member, $tiers);
    }

    public function upgrade(int $pasienId, string $reason, string $username): array
    {
        return $this->changeManualTier($pasienId, 'upgrade', $reason, $username);
    }

    public function downgrade(int $pasienId, string $reason, string $username): array
    {
        return $this->changeManualTier($pasienId, 'downgrade', $reason, $username);
    }

    public function resetAutomatic(int $pasienId, string $reason, string $username): array
    {
        $this->assertSchemaReady();

        DB::transaction(function () use ($pasienId, $reason, $username): void {
            $pasien = $this->findPatient($pasienId);
            $member = $this->memberForUpdate($pasien->id);
            $tiers = $this->activeTiers();
            $target = $this->resolveAutomaticTier($tiers, (float) ($member->total_spending ?? 0));

            if (!$target) {
                throw new DomainException('Master tier aktif belum tersedia.');
            }

            $oldTierId = $member->member_tier_id ? (int) $member->member_tier_id : null;
            $member->allowManualTierChange = true;
            $member->tierChangeSource = 'manual';
            $member->tierChangeReason = $reason;
            $member->tierChangeUser = $username;
            $member->tierChangeAction = 'sync';
            $member->update([
                'member_tier_id' => $target->id,
                'tier_mode' => 'automatic',
                'tier_manual_reason' => null,
                'tier_manual_at' => null,
                'tier_manual_by' => null,
                'updated_by' => $username,
                'updated_at' => now(),
            ]);

            if ($oldTierId === (int) $target->id) {
                PasienMemberTierHistory::query()->create([
                    'pasien_member_id' => $member->id,
                    'pasien_id' => $member->pasien_id,
                    'tier_lama_id' => $oldTierId,
                    'tier_baru_id' => $target->id,
                    'aksi' => 'sync',
                    'sumber' => 'manual',
                    'no_invoice' => null,
                    'total_spending_snapshot' => (float) ($member->total_spending ?? 0),
                    'alasan' => $reason,
                    'effective_at' => now(),
                    'created_by' => $username,
                    'created_at' => now(),
                ]);
            }
        }, 3);

        return $this->detail($pasienId);
    }

    protected function changeManualTier(
        int $pasienId,
        string $direction,
        string $reason,
        string $username,
    ): array {
        $this->assertSchemaReady();

        DB::transaction(function () use ($pasienId, $direction, $reason, $username): void {
            $pasien = $this->findPatient($pasienId);
            $member = $this->memberForUpdate($pasien->id);
            $tiers = $this->activeTiers();
            $target = $this->resolveAdjacentTier($tiers, $member->member_tier_id, $direction);

            if (!$target) {
                throw new DomainException(
                    $direction === 'upgrade'
                        ? 'Pasien sudah berada di tier tertinggi.'
                        : 'Pasien sudah berada di tier terendah atau belum memiliki tier.',
                );
            }

            $member->allowManualTierChange = true;
            $member->tierChangeSource = 'manual';
            $member->tierChangeReason = $reason;
            $member->tierChangeUser = $username;
            $member->tierChangeAction = $direction;
            $member->update([
                'member_tier_id' => $target->id,
                'tier_mode' => 'manual',
                'tier_manual_reason' => $reason,
                'tier_manual_at' => now(),
                'tier_manual_by' => $username,
                'updated_by' => $username,
                'updated_at' => now(),
            ]);
        }, 3);

        return $this->detail($pasienId);
    }

    protected function buildPayload(
        Pasien $pasien,
        ?PasienMember $member,
        Collection $tiers,
    ): array {
        $currentTier = $member?->tier;
        $previousTier = $member
            ? $this->resolveAdjacentTier($tiers, $member->member_tier_id, 'downgrade')
            : null;
        $nextTier = $member
            ? $this->resolveAdjacentTier($tiers, $member->member_tier_id, 'upgrade')
            : null;
        $automaticTier = $member
            ? $this->resolveAutomaticTier($tiers, (float) ($member->total_spending ?? 0))
            : null;

        $history = $member
            ? PasienMemberTierHistory::query()
                ->with(['tierLama', 'tierBaru'])
                ->where('pasien_member_id', $member->id)
                ->orderByDesc('effective_at')
                ->orderByDesc('id')
                ->limit(100)
                ->get()
                ->map(fn (PasienMemberTierHistory $row) => [
                    'id' => $row->id,
                    'action' => $row->aksi,
                    'action_text' => $this->actionText($row->aksi),
                    'source' => $row->sumber,
                    'source_text' => $row->sumber === 'manual' ? 'Manual' : 'Otomatis',
                    'no_invoice' => $row->sumber === 'automatic' ? ($row->no_invoice ?: null) : null,
                    'old_tier' => $this->formatTier($row->tierLama),
                    'new_tier' => $this->formatTier($row->tierBaru),
                    'total_spending_snapshot' => (float) ($row->total_spending_snapshot ?? 0),
                    'reason' => $row->alasan ?: '-',
                    'effective_at' => optional($row->effective_at)->toDateTimeString(),
                    'created_by' => $row->created_by ?: 'system',
                ])
                ->values()
                ->all()
            : [];

        return [
            'patient' => [
                'id' => $pasien->id,
                'no_rm' => $pasien->no_rm,
                'nama' => $pasien->nama,
            ],
            'is_member' => $member !== null,
            'member' => $member ? [
                'id' => $member->id,
                'no_member' => $member->no_member,
                'member_tier_id' => $member->member_tier_id,
                'status' => (int) $member->status,
                'status_text' => $this->memberStatusText((int) $member->status),
                'tanggal_daftar' => optional($member->tanggal_daftar)->toDateString(),
                'tanggal_expired' => optional($member->tanggal_expired)->toDateString(),
                'total_spending' => (float) ($member->total_spending ?? 0),
                'total_point' => (float) ($member->total_point ?? 0),
                'point_terpakai' => (float) ($member->point_terpakai ?? 0),
                'point_sisa' => (float) ($member->point_sisa ?? 0),
                'tier_mode' => $member->tier_mode ?: 'automatic',
                'tier_mode_text' => $member->tier_mode === 'manual' ? 'Manual' : 'Otomatis',
                'tier_manual_reason' => $member->tier_manual_reason,
                'tier_manual_at' => optional($member->tier_manual_at)->toDateTimeString(),
                'tier_manual_by' => $member->tier_manual_by,
            ] : null,
            'current_tier' => $this->formatTier($currentTier),
            'previous_tier' => $this->formatTier($previousTier),
            'next_tier' => $this->formatTier($nextTier),
            'automatic_tier' => $this->formatTier($automaticTier),
            'can_upgrade' => $member !== null && $this->isMemberActive($member) && $nextTier !== null,
            'can_downgrade' => $member !== null && $this->isMemberActive($member) && $previousTier !== null,
            'can_reset_automatic' => $member !== null && $member->tier_mode === 'manual',
            'is_automatic_match' => !$member
                || !$automaticTier
                || (int) ($member->member_tier_id ?? 0) === (int) $automaticTier->id,
            'tiers' => $tiers->map(fn (MasterMemberTier $tier) => [
                ...$this->formatTier($tier),
                'eligible_by_spending' => $member
                    ? (float) ($member->total_spending ?? 0) >= (float) ($tier->minimal_spending ?? 0)
                    : false,
            ])->values()->all(),
            'history' => $history,
        ];
    }

    protected function findPatient(int $pasienId): Pasien
    {
        $pasien = Pasien::query()
            ->whereKey($pasienId)
            ->where(function ($query) {
                $query->whereNull('is_delete')->orWhere('is_delete', 0);
            })
            ->first();

        if (!$pasien) {
            throw (new ModelNotFoundException())->setModel(Pasien::class, [$pasienId]);
        }

        return $pasien;
    }

    protected function memberForUpdate(int $pasienId): PasienMember
    {
        $member = PasienMember::query()
            ->notDeleted()
            ->where('pasien_id', $pasienId)
            ->lockForUpdate()
            ->first();

        if (!$member) {
            throw new DomainException('Pasien belum terdaftar sebagai member.');
        }

        if (!$this->isMemberActive($member)) {
            throw new DomainException('Membership pasien tidak aktif atau sudah kedaluwarsa.');
        }

        return $member;
    }

    protected function activeTiers(): Collection
    {
        return MasterMemberTier::query()
            ->aktif()
            ->orderBy('sort_order')
            ->orderBy('minimal_spending')
            ->orderBy('id')
            ->get();
    }

    protected function resolveAdjacentTier(
        Collection $tiers,
        $currentTierId,
        string $direction,
    ): ?MasterMemberTier {
        if ($tiers->isEmpty()) {
            return null;
        }

        if (!$currentTierId) {
            return $direction === 'upgrade' ? $tiers->first() : null;
        }

        $index = $tiers->search(fn (MasterMemberTier $tier) => (int) $tier->id === (int) $currentTierId);

        if ($index !== false) {
            return $direction === 'upgrade'
                ? $tiers->get($index + 1)
                : $tiers->get($index - 1);
        }

        $current = MasterMemberTier::query()->find($currentTierId);
        if (!$current) {
            return $direction === 'upgrade' ? $tiers->first() : null;
        }

        if ($direction === 'upgrade') {
            return $tiers->first(fn (MasterMemberTier $tier) => $this->tierRank($tier) > $this->tierRank($current));
        }

        return $tiers
            ->reverse()
            ->first(fn (MasterMemberTier $tier) => $this->tierRank($tier) < $this->tierRank($current));
    }

    protected function resolveAutomaticTier(Collection $tiers, float $spending): ?MasterMemberTier
    {
        if ($tiers->isEmpty()) {
            return null;
        }

        return $tiers
            ->filter(fn (MasterMemberTier $tier) => (float) $tier->minimal_spending <= max($spending, 0))
            ->sortByDesc(fn (MasterMemberTier $tier) => $this->tierRank($tier))
            ->first() ?: $tiers->first();
    }

    protected function tierRank(MasterMemberTier $tier): string
    {
        return sprintf(
            '%010d-%020.2f-%020d',
            (int) ($tier->sort_order ?? 0),
            (float) ($tier->minimal_spending ?? 0),
            (int) $tier->id,
        );
    }

    protected function formatTier(?MasterMemberTier $tier): ?array
    {
        if (!$tier) {
            return null;
        }

        return [
            'id' => (int) $tier->id,
            'code' => $tier->kode,
            'name' => $tier->nama,
            'minimal_spending' => (float) ($tier->minimal_spending ?? 0),
            'transaction_min' => (float) ($tier->transaksi_min ?? 0),
            'transaction_max' => $tier->transaksi_max !== null
                ? (float) $tier->transaksi_max
                : null,
            'discount_percent' => (float) ($tier->diskon_persen ?? 0),
            'point_rate' => (float) ($tier->point_rate ?? 0),
            'sort_order' => (int) ($tier->sort_order ?? 0),
            'card_color' => $tier->card_color,
            'text_color' => $tier->text_color,
        ];
    }

    protected function memberStatusText(int $status): string
    {
        return match ($status) {
            1 => 'Aktif',
            2 => 'Kedaluwarsa',
            3 => 'Ditangguhkan',
            9 => 'Dibatalkan',
            default => 'Tidak diketahui',
        };
    }

    protected function actionText(?string $action): string
    {
        return match ($action) {
            'assign' => 'Penetapan Tier',
            'upgrade' => 'Upgrade Tier',
            'downgrade' => 'Downgrade Tier',
            'remove' => 'Penghapusan Tier',
            'sync' => 'Kembali ke Otomatis',
            default => 'Perubahan Tier',
        };
    }

    protected function isMemberActive(PasienMember $member): bool
    {
        if ((int) $member->status !== 1 || (int) $member->is_delete !== 0) {
            return false;
        }

        if (!$member->tanggal_expired) {
            return true;
        }

        return Carbon::parse($member->tanggal_expired)->endOfDay()->gte(now());
    }

    protected function assertSchemaReady(): void
    {
        $requiredTables = ['pasien_member', 'master_member_tier', 'pasien_member_tier_history'];
        foreach ($requiredTables as $table) {
            if (!Schema::hasTable($table)) {
                throw new RuntimeException("Tabel {$table} belum tersedia. Jalankan SQL fitur tier terlebih dahulu.");
            }
        }

        foreach (['tier_mode', 'tier_manual_reason', 'tier_manual_at', 'tier_manual_by'] as $column) {
            if (!Schema::hasColumn('pasien_member', $column)) {
                throw new RuntimeException("Kolom pasien_member.{$column} belum tersedia. Jalankan SQL fitur tier terlebih dahulu.");
            }
        }

        if (!Schema::hasColumn('pasien_member_tier_history', 'no_invoice')) {
            throw new RuntimeException('Kolom pasien_member_tier_history.no_invoice belum tersedia. Jalankan ulang SQL fitur tier.');
        }
    }
}
