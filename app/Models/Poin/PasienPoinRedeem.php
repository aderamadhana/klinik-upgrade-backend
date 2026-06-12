<?php

namespace App\Models\Poin;

use App\Models\Concerns\Auditable;
use App\Models\Master\MasterToko;
use App\Models\Pasien;
use App\Models\PasienMember;
use Illuminate\Database\Eloquent\Model;

class PasienPoinRedeem extends Model
{
    use Auditable;

    public const STATUS_POSTED = 'posted';
    public const STATUS_VOID = 'void';

    protected $table = 'pasien_poin_redeem';

    protected $guarded = [];

    public $timestamps = true;

    protected $casts = [
        'pasien_id' => 'integer',
        'member_id' => 'integer',
        'toko_id' => 'integer',
        'tanggal' => 'datetime',
        'total_poin' => 'integer',
        'total_diskon_nominal' => 'decimal:2',
        'ledger_id' => 'integer',
        'member_point_ledger_id' => 'integer',
        'void_ledger_id' => 'integer',
        'void_at' => 'datetime',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    public function pasien()
    {
        return $this->belongsTo(Pasien::class, 'pasien_id');
    }

    public function member()
    {
        return $this->belongsTo(PasienMember::class, 'member_id');
    }

    public function toko()
    {
        return $this->belongsTo(MasterToko::class, 'toko_id');
    }

    public function ledger()
    {
        return $this->belongsTo(PasienPoinLedger::class, 'ledger_id');
    }

    public function voidLedger()
    {
        return $this->belongsTo(PasienPoinLedger::class, 'void_ledger_id');
    }

    public function memberPointLedger()
    {
        return $this->belongsTo(MemberPointLedger::class, 'member_point_ledger_id');
    }

    public function details()
    {
        return $this->hasMany(PasienPoinRedeemDetail::class, 'redeem_id');
    }

    public function scopePosted($query)
    {
        return $query->where('status', self::STATUS_POSTED);
    }

    public function scopeVoid($query)
    {
        return $query->where('status', self::STATUS_VOID);
    }
}