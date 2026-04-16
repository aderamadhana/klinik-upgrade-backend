<?php

namespace App\Models\master;

class MasterProdukToko extends BaseMasterModel
{
    protected $table = 'master_produk_toko';
    protected $primaryKey = 'id';

    public function produk()
    {
        return $this->belongsTo(MasterProduk::class, 'produk_id', 'id');
    }

    public function toko()
    {
        return $this->belongsTo(MasterToko::class, 'toko_id', 'id');
    }
}