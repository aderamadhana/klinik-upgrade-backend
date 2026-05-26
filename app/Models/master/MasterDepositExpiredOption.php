<?php

namespace App\Models\Master;

class MasterDepositExpiredOption extends BaseMasterModel
{
    protected $table = 'master_deposit_expired_option';

    protected $casts = [
        'jumlah_hari' => 'integer',
        'is_active' => 'integer',
        'is_delete' => 'integer',
        'sort_order' => 'integer',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    public function scopeActive($query)
    {
        return $query->where('is_active', 1)
            ->where('is_delete', 0);
    }
}
