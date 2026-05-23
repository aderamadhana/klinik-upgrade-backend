<?php

namespace App\Models\Master;

use App\Models\Stock\StockProdukToko;

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

    public function supplier()
    {
        return $this->belongsTo(MasterSuplier::class, 'supplier_id', 'id');
    }

    public function stockProdukToko()
    {
        return $this->hasMany(StockProdukToko::class, 'produk_toko_id', 'id');
    }
}