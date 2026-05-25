<?php

namespace App\Models\Master;

use Illuminate\Database\Eloquent\Model;

class MasterMemberTier extends Model
{
    protected $table = 'master_member_tier';

    protected $primaryKey = 'id';

    public $timestamps = true;

    protected $guarded = [];

    protected $casts = [
        'minimal_spending' => 'decimal:2',
        'diskon_persen' => 'decimal:2',
        'point_rate' => 'decimal:4',
        'is_active' => 'integer',
        'is_delete' => 'integer',
        'sort_order' => 'integer',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    public function scopeActive($query)
    {
        return $query->where('is_delete', 0);
    }

    public function scopeAktif($query)
    {
        return $query->where('is_active', 1)->where('is_delete', 0);
    }
}