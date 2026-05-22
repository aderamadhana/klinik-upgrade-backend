<?php

namespace App\Models\Master;

class MasterVoucherDiskonItem extends BaseMasterModel
{
    protected $table = 'master_voucher_diskon_item';
    protected $primaryKey = 'id';

    public function voucher()
    {
        return $this->belongsTo(MasterVoucherDiskon::class, 'voucher_diskon_id', 'id');
    }

    public function treatment()
    {
        return $this->belongsTo(MasterTreatment::class, 'item_id', 'id')
            ->where('item_type', 'treatment');
    }

    public function produk()
    {
        return $this->belongsTo(MasterProduk::class, 'item_id', 'id')
            ->where('item_type', 'produk');
    }

    public function getItemTypeLabelAttribute()
    {
        $map = [
            'treatment' => 'Treatment',
            'produk' => 'Produk',
        ];

        return $map[$this->item_type] ?? '-';
    }

    public function getTipeDiskonItemLabelAttribute()
    {
        $map = [
            'percent' => 'Persen',
            'nominal' => 'Nominal',
        ];

        return $map[$this->tipe_diskon_item] ?? '-';
    }

    public function scopeProduk($query)
    {
        return $query->where('item_type', 'produk');
    }

    public function scopeTreatment($query)
    {
        return $query->where('item_type', 'treatment');
    }

    public function scopeByProdukIds($query, array $produkIds)
    {
        return $query
            ->where('item_type', 'produk')
            ->whereIn('item_id', $produkIds);
    }

    public function scopeByTreatmentIds($query, array $treatmentIds)
    {
        return $query
            ->where('item_type', 'treatment')
            ->whereIn('item_id', $treatmentIds);
    }
}