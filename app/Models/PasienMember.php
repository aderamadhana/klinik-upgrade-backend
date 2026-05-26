<?php

namespace App\Models;

use App\Models\Master\MasterMemberTier;
use App\Models\Master\MasterToko;
use App\Models\Poin\MemberPointLedger;
use App\Models\Poin\PasienPoinLedger;
use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Model;

class PasienMember extends Model
{
    use Auditable;
    protected $table = 'pasien_member';

    protected $guarded = [];

    public $timestamps = true;

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
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

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

    public function scopeActive($query)
    {
        return $query->where('status', 1)
            ->where('is_delete', 0);
    }

    public function scopeNotDeleted($query)
    {
        return $query->where('is_delete', 0);
    }
}
