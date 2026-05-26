<?php

namespace App\Models\Master;

use App\Models\Pasien;

class MasterVoucherDiskonKode extends BaseMasterModel
{
    protected $table = 'master_voucher_diskon_kode';

    protected $casts = [
        'voucher_diskon_id' => 'integer',
        'status_kode' => 'integer',
        'used_at' => 'datetime',
        'expired_at' => 'datetime',
        'redeemed_pasien_id' => 'integer',
        'is_delete' => 'integer',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    public function voucher()
    {
        return $this->belongsTo(MasterVoucherDiskon::class, 'voucher_diskon_id');
    }

    public function pasienRedeem()
    {
        return $this->belongsTo(Pasien::class, 'redeemed_pasien_id');
    }

    public function scopeAvailable($query)
    {
        return $query->where('status_kode', 1)
            ->where('is_delete', 0);
    }

    public function scopeUsed($query)
    {
        return $query->where('status_kode', 2)
            ->where('is_delete', 0);
    }

    public function scopeNotDeleted($query)
    {
        return $query->where('is_delete', 0);
    }
}
