<?php

namespace App\Models\Master;

class MasterVoucherDiskon extends BaseMasterModel
{
    protected $table = 'master_voucher_diskon';
    protected $primaryKey = 'id';

    public function toko()
    {
        return $this->belongsTo(MasterToko::class, 'toko_id', 'id');
    }

    public function items()
    {
        return $this->hasMany(MasterVoucherDiskonItem::class, 'voucher_diskon_id', 'id')
            ->active();
    }

    public function semuaItems()
    {
        return $this->hasMany(MasterVoucherDiskonItem::class, 'voucher_diskon_id', 'id');
    }

    public function scopeAktifVoucher($query)
    {
        return $query->where('status_voucher', 1);
    }

    public function scopeDraftVoucher($query)
    {
        return $query->where('status_voucher', 0);
    }

    public function scopeNonaktifVoucher($query)
    {
        return $query->where('status_voucher', 2);
    }

    public function getStatusVoucherLabelAttribute()
    {
        $map = [
            0 => 'Draft',
            1 => 'Aktif',
            2 => 'Nonaktif',
        ];

        return $map[(int) $this->status_voucher] ?? '-';
    }

    public function getModeVoucherLabelAttribute()
    {
        $map = [
            'direct' => 'Direct Code',
            'generate' => 'Generate Banyak Kode',
        ];

        return $map[$this->mode_voucher] ?? '-';
    }

    public function getTipeDiskonLabelAttribute()
    {
        $map = [
            'percent' => 'Persen',
            'nominal' => 'Nominal',
        ];

        return $map[$this->tipe_diskon] ?? '-';
    }
}