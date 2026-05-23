<?php

namespace App\Models\Master;
use App\Models\Stock\StockProdukToko;
use App\Models\Stock\StockMutasiProduk;
use App\Models\Stock\StockPenerimaan;
use App\Models\Stock\StockPenyesuaian;
use App\Models\Stock\StockReservasiProduk;

class MasterTempatProduk extends BaseMasterModel
{
    protected $table = 'master_tempat_produk';
    protected $primaryKey = 'id';

    public function produk()
    {
        return $this->hasMany(MasterProduk::class, 'tempat_produk_id', 'id');
    }

    public function stockProdukToko()
    {
        return $this->hasMany(StockProdukToko::class, 'tempat_produk_id', 'id');
    }

    public function mutasiStock()
    {
        return $this->hasMany(StockMutasiProduk::class, 'tempat_produk_id', 'id');
    }

    public function penerimaanStock()
    {
        return $this->hasMany(StockPenerimaan::class, 'tempat_produk_id', 'id');
    }

    public function penyesuaianStock()
    {
        return $this->hasMany(StockPenyesuaian::class, 'tempat_produk_id', 'id');
    }

    public function reservasiStock()
    {
        return $this->hasMany(StockReservasiProduk::class, 'tempat_produk_id', 'id');
    }
}