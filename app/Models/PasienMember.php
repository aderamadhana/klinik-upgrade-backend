<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Models\Master\MasterMemberTier;
use App\Models\Master\MasterToko;
use App\Models\Poin\MemberPointLedger;
use App\Models\Poin\PasienPoinLedger;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class PasienMember extends Model
{
    use Auditable;

    protected $table = 'pasien_member';

    protected $guarded = [];

    public $timestamps = true;

    /**
     * Properti runtime. Tidak disimpan sebagai kolom tabel.
     */
    public bool $allowManualTierChange = false;
    public string $tierChangeSource = 'automatic';
    public ?string $tierChangeReason = null;
    public ?string $tierChangeUser = null;
    public ?string $tierChangeAction = null;

    protected $casts = [
        'pasien_id' => 'integer',
        'member_tier_id' => 'integer',
        'toko_daftar_id' => 'integer',
        'tanggal_daftar' => 'date',
        'tanggal_expired' => 'date',
        'total_spending' => 'decimal:2',
        'total_point' => 'decimal:2',
        'point_terpakai' => 'decimal:2',
        'point_sisa' => 'decimal:2',
        'status' => 'integer',
        'is_delete' => 'integer',
        'tier_manual_at' => 'datetime',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::updating(function (PasienMember $member): void {
            if (!$member->isDirty('member_tier_id')) {
                return;
            }

            if (!Schema::hasColumn($member->getTable(), 'tier_mode')) {
                return;
            }

            $modeSebelumnya = (string) ($member->getOriginal('tier_mode') ?: 'automatic');

            // Tier manual tidak boleh ditimpa proses otomatis pembayaran.
            if ($modeSebelumnya === 'manual' && !$member->allowManualTierChange) {
                $member->setAttribute(
                    'member_tier_id',
                    $member->getOriginal('member_tier_id'),
                );
            }
        });

        static::created(function (PasienMember $member): void {
            if (!empty($member->member_tier_id)) {
                $member->writeTierHistory(null, (int) $member->member_tier_id);
            }
        });

        static::updated(function (PasienMember $member): void {
            if (!$member->wasChanged('member_tier_id')) {
                return;
            }

            $member->writeTierHistory(
                $member->getOriginal('member_tier_id'),
                $member->member_tier_id,
            );
        });
    }

    public function pasien()
    {
        return $this->belongsTo(Pasien::class, 'pasien_id');
    }

    public function tier()
    {
        return $this->belongsTo(MasterMemberTier::class, 'member_tier_id');
    }

    public function tokoDaftar()
    {
        return $this->belongsTo(MasterToko::class, 'toko_daftar_id');
    }

    public function memberPointLedgers()
    {
        return $this->hasMany(MemberPointLedger::class, 'member_id');
    }

    public function pasienPoinLedgers()
    {
        return $this->hasMany(PasienPoinLedger::class, 'pasien_id', 'pasien_id');
    }

    public function tierHistories()
    {
        return $this->hasMany(PasienMemberTierHistory::class, 'pasien_member_id');
    }

    public function scopeActive($query)
    {
        return $query->where('status', 1)
            ->where('is_delete', 0);
    }

    public function scopeNotDeleted($query)
    {
        return $query->where('is_delete', 0);
    }

    protected function writeTierHistory($tierLamaId, $tierBaruId): void
    {
        if (!Schema::hasTable('pasien_member_tier_history')) {
            return;
        }

        $tierLamaId = $tierLamaId ? (int) $tierLamaId : null;
        $tierBaruId = $tierBaruId ? (int) $tierBaruId : null;

        if ($tierLamaId === $tierBaruId) {
            return;
        }

        $tiers = MasterMemberTier::query()
            ->whereIn('id', array_values(array_filter([$tierLamaId, $tierBaruId])))
            ->get()
            ->keyBy('id');

        $tierLama = $tierLamaId ? $tiers->get($tierLamaId) : null;
        $tierBaru = $tierBaruId ? $tiers->get($tierBaruId) : null;
        $aksi = $this->tierChangeAction ?: 'change';

        if (!$this->tierChangeAction && !$tierLamaId && $tierBaruId) {
            $aksi = 'assign';
        } elseif (!$this->tierChangeAction && $tierLamaId && !$tierBaruId) {
            $aksi = 'remove';
        } elseif (!$this->tierChangeAction && $tierLama && $tierBaru) {
            $urutanLama = [(int) ($tierLama->sort_order ?? 0), (float) ($tierLama->minimal_spending ?? 0), (int) $tierLama->id];
            $urutanBaru = [(int) ($tierBaru->sort_order ?? 0), (float) ($tierBaru->minimal_spending ?? 0), (int) $tierBaru->id];
            $aksi = $urutanBaru > $urutanLama ? 'upgrade' : 'downgrade';
        }

        $source = $this->tierChangeSource ?: 'automatic';

        PasienMemberTierHistory::query()->create([
            'pasien_member_id' => $this->id,
            'pasien_id' => $this->pasien_id,
            'tier_lama_id' => $tierLamaId,
            'tier_baru_id' => $tierBaruId,
            'aksi' => $aksi,
            'sumber' => $source,
            'no_invoice' => $this->resolveTierHistoryInvoiceNo($source),
            'total_spending_snapshot' => (float) ($this->total_spending ?? 0),
            'alasan' => $this->tierChangeReason
                ?: 'Penyesuaian tier otomatis berdasarkan total spending member.',
            'effective_at' => now(),
            'created_by' => $this->tierChangeUser
                ?: (string) ($this->updated_by ?: $this->created_by ?: 'system'),
            'created_at' => now(),
        ]);
    }
    protected function resolveTierHistoryInvoiceNo(string $source): ?string
    {
        if (
            $source !== 'automatic'
            || empty($this->pasien_id)
            || !Schema::hasTable('pembayaran_invoice')
            || !Schema::hasColumn('pembayaran_invoice', 'no_invoice')
        ) {
            return null;
        }

        // Saat perubahan tier berasal dari pembayaran, ledger poin sudah dibuat
        // sebelum total spending dan tier member diperbarui. Relasi ini menjadi
        // sumber invoice yang paling presisi dan menghindari salah atribusi ketika
        // pasien memiliki lebih dari satu invoice dalam waktu berdekatan.
        if (
            !empty($this->id)
            && Schema::hasTable('member_point_ledger')
            && Schema::hasColumn('member_point_ledger', 'pembayaran_id')
            && Schema::hasColumn('member_point_ledger', 'member_id')
        ) {
            $ledgerQuery = DB::table('member_point_ledger as mpl')
                ->join('pembayaran_invoice as pi', 'pi.id', '=', 'mpl.pembayaran_id')
                ->where('mpl.member_id', $this->id)
                ->whereNotNull('mpl.pembayaran_id')
                ->whereNotNull('pi.no_invoice')
                ->where('pi.no_invoice', '!=', '');

            if (Schema::hasColumn('member_point_ledger', 'transaksi_type')) {
                $ledgerQuery->where('mpl.transaksi_type', 1);
            }

            if (Schema::hasColumn('member_point_ledger', 'is_void')) {
                $ledgerQuery->where(function ($builder) {
                    $builder->whereNull('mpl.is_void')->orWhere('mpl.is_void', 0);
                });
            }

            if (Schema::hasColumn('pembayaran_invoice', 'is_delete')) {
                $ledgerQuery->where(function ($builder) {
                    $builder->whereNull('pi.is_delete')->orWhere('pi.is_delete', 0);
                });
            }

            $recentAt = now()->subMinutes(10);
            if (Schema::hasColumn('member_point_ledger', 'created_at')) {
                $ledgerQuery->where('mpl.created_at', '>=', $recentAt);
            } elseif (Schema::hasColumn('member_point_ledger', 'tanggal_transaksi')) {
                $ledgerQuery->where('mpl.tanggal_transaksi', '>=', $recentAt);
            }

            $invoiceNo = $ledgerQuery
                ->orderByDesc('mpl.id')
                ->value('pi.no_invoice');

            if ($invoiceNo) {
                return (string) $invoiceNo;
            }
        }

        // Fallback untuk penetapan tier saat member baru dibuat. Pada tahap ini
        // ledger poin belum selalu tersedia, tetapi invoice pembayaran sudah ada.
        $recentAt = now()->subMinutes(10);
        $query = DB::table('pembayaran_invoice')
            ->where('pasien_id', $this->pasien_id)
            ->whereNotNull('no_invoice')
            ->where('no_invoice', '!=', '');

        if (Schema::hasColumn('pembayaran_invoice', 'is_delete')) {
            $query->where(function ($builder) {
                $builder->whereNull('is_delete')->orWhere('is_delete', 0);
            });
        }

        if (Schema::hasColumn('pembayaran_invoice', 'member_id') && !empty($this->id)) {
            $query->where(function ($builder) {
                $builder
                    ->where('member_id', $this->id)
                    ->orWhereNull('member_id');
            });
        }

        $dateColumns = array_values(array_filter(
            ['tanggal_lunas', 'updated_at', 'created_at', 'tanggal_invoice'],
            fn (string $column) => Schema::hasColumn('pembayaran_invoice', $column),
        ));

        if ($dateColumns) {
            $query->where(function ($builder) use ($recentAt, $dateColumns) {
                foreach ($dateColumns as $index => $column) {
                    if ($index === 0) {
                        $builder->where($column, '>=', $recentAt);
                    } else {
                        $builder->orWhere($column, '>=', $recentAt);
                    }
                }
            });
        }

        if (Schema::hasColumn('pembayaran_invoice', 'member_id') && !empty($this->id)) {
            $query->orderByRaw(
                'CASE WHEN member_id = ? THEN 0 ELSE 1 END',
                [$this->id],
            );
        }

        if ($dateColumns) {
            $query->orderByRaw(
                'COALESCE(' . implode(', ', $dateColumns) . ') DESC',
            );
        }

        $invoiceNo = $query
            ->orderByDesc('id')
            ->value('no_invoice');

        return $invoiceNo ? (string) $invoiceNo : null;
    }

}
