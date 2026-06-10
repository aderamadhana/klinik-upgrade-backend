<?php

namespace App\Models;

use App\Models\Master\MasterMemberTier;
use Illuminate\Database\Eloquent\Model;

class PasienMemberTierHistory extends Model
{
    protected $table = 'pasien_member_tier_history';

    protected $guarded = [];

    public $timestamps = false;

    protected $casts = [
        'pasien_member_id' => 'integer',
        'pasien_id' => 'integer',
        'tier_lama_id' => 'integer',
        'tier_baru_id' => 'integer',
        'total_spending_snapshot' => 'decimal:2',
        'effective_at' => 'datetime',
        'created_at' => 'datetime',
    ];

    public function member()
    {
        return $this->belongsTo(PasienMember::class, 'pasien_member_id');
    }

    public function pasien()
    {
        return $this->belongsTo(Pasien::class, 'pasien_id');
    }

    public function tierLama()
    {
        return $this->belongsTo(MasterMemberTier::class, 'tier_lama_id');
    }

    public function tierBaru()
    {
        return $this->belongsTo(MasterMemberTier::class, 'tier_baru_id');
    }
}
