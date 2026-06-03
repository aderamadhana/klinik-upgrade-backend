<?php

namespace App\Services\Pembayaran;

use App\Models\Master\MasterMemberTier;
use App\Models\Master\MasterPoinRule;
use App\Models\Poin\MemberPointLedger;
use App\Models\PasienMember;
use App\Models\Poin\PasienPoinLedger;
use App\Models\Pembayaran\PembayaranInvoice;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class PaymentMemberPointService
{
    public function applyMemberBenefitToInvoice(PembayaranInvoice $invoice, string $username = 'system'): void
    {
        $member = $this->resolveActivePatientMember((int) ($invoice->pasien_id ?? 0));
        $isExistingActiveMember = $member !== null;
        $tier = $member ? $this->resolveMemberTier((int) ($member->member_tier_id ?? 0)) : null;

        $base = $this->calculateMemberPointBase($invoice);
        $eligibleNetBeforeMember = max($base['treatment_net'] + $base['produk_net'], 0);

        $diskonMember = 0.0;
        $tierDiscount = (float) ($tier?->diskon_persen ?? 0);
        if ($isExistingActiveMember && $tierDiscount > 0 && $eligibleNetBeforeMember > 0) {
            $diskonMember = round(($eligibleNetBeforeMember * $tierDiscount) / 100, 2);
            $diskonMember = min($diskonMember, $eligibleNetBeforeMember);
        }

        $treatmentMemberDiscountShare = 0.0;
        if ($diskonMember > 0 && $eligibleNetBeforeMember > 0 && $base['treatment_net'] > 0) {
            $treatmentMemberDiscountShare = round(($diskonMember * $base['treatment_net']) / $eligibleNetBeforeMember, 2);
            $treatmentMemberDiscountShare = min($treatmentMemberDiscountShare, $base['treatment_net']);
        }

        $pointBase = max($base['treatment_net'] - $treatmentMemberDiscountShare, 0);
        $pointEarned = $this->calculatePointEarned($pointBase, $member, $tier);

        if (!$member && $pointEarned > 0) {
            $member = $this->ensurePatientMemberForEarnedPoint($invoice, $username);
            $tier = $member ? $this->resolveMemberTier((int) ($member->member_tier_id ?? 0)) : null;
        }

        $invoice->update($this->onlyExistingColumns('pembayaran_invoice', [
            'member_id' => $member?->id,
            'member_no' => $member?->no_member,
            'member_tier_id' => $member?->member_tier_id,
            'member_tier_nama' => $tier?->nama,
            'diskon_member_amount' => $diskonMember,
            'point_earned' => $pointEarned,
            'poin' => $pointEarned,
            'updated_by' => $username,
            'updated_at' => now(),
        ]));

        $invoice->forceFill([
            'member_id' => $member?->id,
            'member_no' => $member?->no_member,
            'member_tier_id' => $member?->member_tier_id,
            'member_tier_nama' => $tier?->nama,
            'diskon_member_amount' => $diskonMember,
            'point_earned' => $pointEarned,
            'poin' => $pointEarned,
        ]);
    }

    public function processEarnedPointLedger(PembayaranInvoice $invoice, string $username = 'system'): void
    {
        $pointEarned = (int) round((float) ($invoice->point_earned ?? $invoice->poin ?? 0));
        if ($pointEarned <= 0 || empty($invoice->pasien_id)) {
            return;
        }

        $base = $this->calculateMemberPointBase($invoice);
        $pointBase = max((float) ($base['point_base'] ?? $base['treatment_net'] ?? 0), 0);
        $spendingBase = max((float) ($invoice->grand_total ?? 0), 0);
        $rule = $this->resolveActivePoinRule();

        if (!empty($invoice->member_id) && class_exists(MemberPointLedger::class) && Schema::hasTable('member_point_ledger')) {
            $exists = $this->activeMemberPointLedgerQuery(
                MemberPointLedger::query()
                    ->where('pembayaran_id', $invoice->id)
                    ->where('transaksi_type', MemberPointLedger::TYPE_EARN)
            )->exists();

            if (!$exists) {
                MemberPointLedger::query()->create($this->onlyExistingColumns('member_point_ledger', [
                    'member_id' => $invoice->member_id,
                    'pasien_id' => $invoice->pasien_id,
                    'pembayaran_id' => $invoice->id,
                    'registrasi_id' => $invoice->registrasi_id,
                    'transaksi_type' => MemberPointLedger::TYPE_EARN,
                    'point_masuk' => $pointEarned,
                    'point_keluar' => 0,
                    'nominal_referensi' => $spendingBase,
                    'catatan' => 'Earn point pembayaran ' . $invoice->no_invoice,
                    'tanggal_transaksi' => now(),
                    'is_void' => 0,
                    'created_by' => $username,
                    'updated_by' => $username,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]));

                $this->incrementMemberBalance((int) $invoice->member_id, $pointEarned, $spendingBase, $username);
            }
        }

        if (class_exists(PasienPoinLedger::class) && Schema::hasTable('pasien_poin_ledger')) {
            $existsPasienLedger = $this->activePasienPoinLedgerQuery(
                PasienPoinLedger::query()
                    ->where('source_table', 'pembayaran_invoice')
                    ->where('source_id', $invoice->id)
                    ->where('tipe_mutasi', 'earn')
            )->exists();

            if (!$existsPasienLedger) {
                $lastSaldo = (int) $this->activePasienPoinLedgerQuery(
                    PasienPoinLedger::query()->where('pasien_id', $invoice->pasien_id)
                )
                    ->orderByDesc('id')
                    ->value('saldo_setelah');

                PasienPoinLedger::query()->create($this->onlyExistingColumns('pasien_poin_ledger', [
                    'pasien_id' => $invoice->pasien_id,
                    'toko_id' => $invoice->toko_id,
                    'source_table' => 'pembayaran_invoice',
                    'source_id' => $invoice->id,
                    'source_no' => $invoice->no_invoice,
                    'tanggal' => now(),
                    'tipe_mutasi' => 'earn',
                    'poin_masuk' => $pointEarned,
                    'poin_keluar' => 0,
                    'saldo_sebelum' => $lastSaldo,
                    'saldo_setelah' => $lastSaldo + $pointEarned,
                    'nominal_transaksi' => $pointBase,
                    'rule_id' => $rule?->id,
                    'keterangan' => 'Earn point pembayaran ' . $invoice->no_invoice,
                    'is_void' => 0,
                    'created_by' => $this->resolveNumericUserId(),
                    'created_at' => now(),
                ]));
            }
        }
    }

    public function rollbackPointLedger(PembayaranInvoice $invoice, string $reason = 'VOID', string $username = 'system'): void
    {
        if (Schema::hasTable('member_point_ledger')) {
            $ledgers = $this->activeMemberPointLedgerQuery(
                MemberPointLedger::query()->where('pembayaran_id', $invoice->id)
            )
                ->lockForUpdate()
                ->get();

            foreach ($ledgers as $ledger) {
                $ledger->update($this->onlyExistingColumns('member_point_ledger', [
                    'is_void' => 1,
                    'catatan' => trim((string) ($ledger->catatan ?? '') . ' | VOID: ' . $reason),
                    'updated_by' => $username,
                    'updated_at' => now(),
                ]));

                if ((int) ($ledger->transaksi_type ?? 0) === MemberPointLedger::TYPE_EARN) {
                    $this->decrementMemberBalance(
                        (int) $ledger->member_id,
                        (float) ($ledger->point_masuk ?? 0),
                        (float) ($ledger->nominal_referensi ?? 0),
                        $username
                    );
                }
            }
        }

        if (Schema::hasTable('pasien_poin_ledger')) {
            $this->activePasienPoinLedgerQuery(
                PasienPoinLedger::query()
                    ->where('source_table', 'pembayaran_invoice')
                    ->where('source_id', $invoice->id)
            )->update($this->onlyExistingColumns('pasien_poin_ledger', [
                    'is_void' => 1,
                    'keterangan' => DB::raw("CONCAT(COALESCE(keterangan, ''), ' | VOID: " . addslashes($reason) . "')"),
                ]));
        }
    }

    public function calculateMemberPointBase(PembayaranInvoice $invoice): array
    {
        if (!Schema::hasTable('pembayaran_invoice_item')) {
            return [
                'treatment_net' => 0.0,
                'produk_net' => 0.0,
                'point_base' => 0.0,
            ];
        }

        $items = DB::table('pembayaran_invoice_item')
            ->where('pembayaran_id', $invoice->id)
            ->whereIn('item_type', [2, 3])
            ->where(function ($q) {
                $q->whereNull('is_delete')->orWhere('is_delete', 0);
            })
            ->get();

        $itemBases = [];
        foreach ($items as $item) {
            $base = (float) ($item->subtotal_after_diskon_subtotal ?? 0);
            if ($base <= 0) {
                $base = max((float) ($item->subtotal ?? 0) - (float) ($item->diskon_subtotal_amount ?? 0), 0);
            }

            $itemBases[(int) $item->id] = [
                'item_type' => (int) ($item->item_type ?? 0),
                'base' => $base,
                'promo' => 0.0,
            ];
        }

        if (!empty($itemBases) && Schema::hasTable('pembayaran_invoice_promo')) {
            $this->allocatePromoToItemBases($invoice, $itemBases);
        }

        $treatmentNet = 0.0;
        $produkNet = 0.0;

        foreach ($itemBases as $row) {
            $net = max((float) $row['base'] - (float) $row['promo'], 0);
            if ((int) $row['item_type'] === 2) {
                $treatmentNet += $net;
            } elseif ((int) $row['item_type'] === 3) {
                $produkNet += $net;
            }
        }

        return [
            'treatment_net' => round($treatmentNet, 2),
            'produk_net' => round($produkNet, 2),
            'point_base' => round($treatmentNet, 2),
        ];
    }

    protected function allocatePromoToItemBases(PembayaranInvoice $invoice, array &$itemBases): void
    {
        $promos = DB::table('pembayaran_invoice_promo as pip')
            ->leftJoin('master_voucher_diskon as mvd', 'mvd.id', '=', 'pip.voucher_id')
            ->where('pip.pembayaran_id', $invoice->id)
            ->where(function ($q) {
                $q->whereNull('pip.is_delete')->orWhere('pip.is_delete', 0);
            })
            ->get([
                'pip.pembayaran_item_id',
                'pip.scope_type',
                'pip.diskon_amount',
                'mvd.jenis_voucher_id',
            ]);

        foreach ($promos as $promo) {
            $amount = (float) ($promo->diskon_amount ?? 0);
            if ($amount <= 0) {
                continue;
            }

            $itemId = (int) ($promo->pembayaran_item_id ?? 0);
            if ($itemId > 0 && isset($itemBases[$itemId])) {
                $itemBases[$itemId]['promo'] += min($amount, $itemBases[$itemId]['base']);
                continue;
            }

            $jenisVoucher = (int) ($promo->jenis_voucher_id ?? 0);
            $targetTypes = match ($jenisVoucher) {
                1 => [2],
                2 => [3],
                default => [2, 3],
            };

            $eligibleTotal = 0.0;
            foreach ($itemBases as $row) {
                if (in_array((int) $row['item_type'], $targetTypes, true)) {
                    $eligibleTotal += max((float) $row['base'] - (float) $row['promo'], 0);
                }
            }

            if ($eligibleTotal <= 0) {
                continue;
            }

            $allocated = 0.0;
            $eligibleKeys = array_values(array_filter(array_keys($itemBases), function ($key) use ($itemBases, $targetTypes) {
                return in_array((int) $itemBases[$key]['item_type'], $targetTypes, true)
                    && max((float) $itemBases[$key]['base'] - (float) $itemBases[$key]['promo'], 0) > 0;
            }));
            $lastKey = end($eligibleKeys);

            foreach ($eligibleKeys as $key) {
                $remainingBase = max((float) $itemBases[$key]['base'] - (float) $itemBases[$key]['promo'], 0);
                if ($key === $lastKey) {
                    $share = round($amount - $allocated, 2);
                } else {
                    $share = round(($remainingBase / $eligibleTotal) * $amount, 2);
                    $allocated += $share;
                }

                $itemBases[$key]['promo'] += min(max($share, 0), $remainingBase);
            }
        }
    }

    protected function calculatePointEarned(float $pointBase, ?PasienMember $member = null, ?MasterMemberTier $tier = null): int
    {
        if ($pointBase <= 0) {
            return 0;
        }

        $rule = $this->resolveActivePoinRule();
        if (!$rule) {
            return 0;
        }

        $minimal = (float) ($rule->minimal_transaksi ?? 0);
        if ($minimal > 0 && $pointBase < $minimal) {
            return 0;
        }

        $nominalPerPoin = (float) ($rule->nominal_per_poin ?? 0);
        if ($nominalPerPoin <= 0) {
            return 0;
        }

        $basePoint = (int) ((int) ($rule->is_berlaku_kelipatan ?? 1) === 1
            ? floor($pointBase / $nominalPerPoin)
            : ($pointBase >= $nominalPerPoin ? 1 : 0));

        if ($basePoint <= 0) {
            return 0;
        }

        $tierMultiplier = (float) ($tier?->point_rate ?? 0);
        if ($member && $tierMultiplier > 0) {
            return max((int) floor($basePoint * $tierMultiplier), 0);
        }

        return max($basePoint, 0);
    }

    protected function ensurePatientMemberForEarnedPoint(PembayaranInvoice $invoice, string $username = 'system'): ?PasienMember
    {
        if (!Schema::hasTable('pasien_member') || empty($invoice->pasien_id)) {
            return null;
        }

        $pasienId = (int) $invoice->pasien_id;
        $spendingBase = max((float) ($invoice->grand_total ?? 0), 0);
        $tier = $this->resolveTierBySpending($spendingBase);

        $member = PasienMember::query()
            ->where('pasien_id', $pasienId)
            ->lockForUpdate()
            ->first();

        $payload = $this->onlyExistingColumns('pasien_member', [
            'pasien_id' => $pasienId,
            'no_member' => $member?->no_member ?: $this->generateMemberNumber($pasienId),
            'member_tier_id' => $tier?->id,
            'toko_daftar_id' => $invoice->toko_id,
            'tanggal_daftar' => Carbon::today()->toDateString(),
            'tanggal_expired' => null,
            'status' => 1,
            'is_delete' => 0,
            'created_by' => $username,
            'updated_by' => $username,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        if ($member) {
            unset($payload['created_by'], $payload['created_at']);
            $member->update($payload);
            return $member->fresh('tier');
        }

        return PasienMember::query()->create($payload)->fresh('tier');
    }

    protected function resolveTierBySpending(float $spending): ?MasterMemberTier
    {
        if (!Schema::hasTable('master_member_tier')) {
            return null;
        }

        $query = $this->activeMasterMemberTierQuery(MasterMemberTier::query());

        $tier = (clone $query)
            ->where('minimal_spending', '<=', max($spending, 0))
            ->orderByDesc('minimal_spending')
            ->orderByDesc('id')
            ->first();

        if ($tier) {
            return $tier;
        }

        return $query
            ->orderBy('minimal_spending')
            ->orderBy('id')
            ->first();
    }

    protected function generateMemberNumber(int $pasienId): string
    {
        return 'MBR-' . str_pad((string) $pasienId, 8, '0', STR_PAD_LEFT);
    }

    protected function resolveActivePatientMember(int $pasienId): ?PasienMember
    {
        if ($pasienId <= 0 || !Schema::hasTable('pasien_member')) {
            return null;
        }

        return $this->activePasienMemberQuery(PasienMember::query())
            ->where('pasien_id', $pasienId)
            ->where(function ($q) {
                $q->whereNull('tanggal_expired')->orWhereDate('tanggal_expired', '>=', Carbon::today());
            })
            ->with('tier')
            ->lockForUpdate()
            ->orderByDesc('id')
            ->first();
    }

    protected function resolveMemberTier(int $tierId): ?MasterMemberTier
    {
        if ($tierId <= 0 || !Schema::hasTable('master_member_tier')) {
            return null;
        }

        return $this->activeMasterMemberTierQuery(MasterMemberTier::query())
            ->whereKey($tierId)
            ->first();
    }

    protected function resolveActivePoinRule(): ?MasterPoinRule
    {
        if (!Schema::hasTable('master_poin_rules')) {
            return null;
        }

        return $this->activeMasterPoinRuleQuery(MasterPoinRule::query())
            ->where(function ($q) {
                $q->whereNull('berlaku_mulai')->orWhereDate('berlaku_mulai', '<=', Carbon::today());
            })
            ->where(function ($q) {
                $q->whereNull('berlaku_sampai')->orWhereDate('berlaku_sampai', '>=', Carbon::today());
            })
            ->orderByDesc('berlaku_mulai')
            ->orderByDesc('id')
            ->first();
    }

    protected function incrementMemberBalance(int $memberId, float $pointEarned, float $spendingBase, string $username): void
    {
        if ($memberId <= 0 || !Schema::hasTable('pasien_member')) {
            return;
        }

        PasienMember::query()
            ->whereKey($memberId)
            ->update($this->onlyExistingColumns('pasien_member', [
                'total_spending' => DB::raw('total_spending + ' . $spendingBase),
                'total_point' => DB::raw('total_point + ' . $pointEarned),
                'point_sisa' => DB::raw('point_sisa + ' . $pointEarned),
                'updated_by' => $username,
                'updated_at' => now(),
            ]));

        $this->refreshMemberTierByCurrentSpending($memberId, $username);
    }

    protected function refreshMemberTierByCurrentSpending(int $memberId, string $username): void
    {
        if ($memberId <= 0 || !Schema::hasTable('pasien_member')) {
            return;
        }

        $member = PasienMember::query()->whereKey($memberId)->lockForUpdate()->first();
        if (!$member) {
            return;
        }

        $tier = $this->resolveTierBySpending((float) ($member->total_spending ?? 0));
        if (!$tier || (int) ($member->member_tier_id ?? 0) === (int) $tier->id) {
            return;
        }

        $member->update($this->onlyExistingColumns('pasien_member', [
            'member_tier_id' => $tier->id,
            'updated_by' => $username,
            'updated_at' => now(),
        ]));
    }

    protected function decrementMemberBalance(int $memberId, float $pointEarned, float $spendingBase, string $username): void
    {
        if ($memberId <= 0 || !Schema::hasTable('pasien_member')) {
            return;
        }

        PasienMember::query()
            ->whereKey($memberId)
            ->update($this->onlyExistingColumns('pasien_member', [
                'total_spending' => DB::raw('GREATEST(total_spending - ' . $spendingBase . ', 0)'),
                'total_point' => DB::raw('GREATEST(total_point - ' . $pointEarned . ', 0)'),
                'point_sisa' => DB::raw('GREATEST(point_sisa - ' . $pointEarned . ', 0)'),
                'updated_by' => $username,
                'updated_at' => now(),
            ]));
    }

    protected function activeMemberPointLedgerQuery($query)
    {
        if (Schema::hasColumn('member_point_ledger', 'is_void')) {
            $query->where(function ($q) {
                $q->whereNull('is_void')->orWhere('is_void', 0);
            });
        }

        if (Schema::hasColumn('member_point_ledger', 'is_delete')) {
            $query->where(function ($q) {
                $q->whereNull('is_delete')->orWhere('is_delete', 0);
            });
        }

        return $query;
    }

    protected function activePasienPoinLedgerQuery($query)
    {
        if (Schema::hasColumn('pasien_poin_ledger', 'is_void')) {
            $query->where(function ($q) {
                $q->whereNull('is_void')->orWhere('is_void', 0);
            });
        }

        if (Schema::hasColumn('pasien_poin_ledger', 'is_delete')) {
            $query->where(function ($q) {
                $q->whereNull('is_delete')->orWhere('is_delete', 0);
            });
        }

        return $query;
    }

    protected function activePasienMemberQuery($query)
    {
        if (Schema::hasColumn('pasien_member', 'is_delete')) {
            $query->where(function ($q) {
                $q->whereNull('is_delete')->orWhere('is_delete', 0);
            });
        }

        if (Schema::hasColumn('pasien_member', 'status')) {
            $query->where('status', 1);
        }

        return $query;
    }

    protected function activeMasterMemberTierQuery($query)
    {
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

        return $query;
    }

    protected function activeMasterPoinRuleQuery($query)
    {
        if (Schema::hasColumn('master_poin_rules', 'is_delete')) {
            $query->where(function ($q) {
                $q->whereNull('is_delete')->orWhere('is_delete', 0);
            });
        }

        if (Schema::hasColumn('master_poin_rules', 'is_active')) {
            $query->where(function ($q) {
                $q->whereNull('is_active')->orWhere('is_active', 1);
            });
        }

        return $query;
    }

    protected function resolveNumericUserId(): ?int
    {
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
