<?php

namespace App\Models\Stock;

use App\Models\Master\MasterProduk;
use App\Models\Master\MasterProdukToko;
use App\Models\Master\MasterTempatProduk;
use App\Models\Master\MasterToko;

class StockPenerimaanDetail extends BaseStockModel
{
    protected $table = 'stock_penerimaan_detail';
    protected $primaryKey = 'id';

    protected $casts = [
        'penerimaan_id' => 'integer',

        'toko_id' => 'integer',
        'tempat_produk_id' => 'integer',
        'produk_toko_id' => 'integer',
        'produk_id' => 'integer',

        'qty' => 'decimal:4',
        'harga_beli' => 'decimal:2',
        'harga_jual' => 'decimal:2',
        'subtotal' => 'decimal:2',

        'is_delete' => 'integer',
    ];

    public function penerimaan()
    {
        return $this->belongsTo(StockPenerimaan::class, 'penerimaan_id', 'id');
    }

    public function produkToko()
    {
        return $this->belongsTo(MasterProdukToko::class, 'produk_toko_id', 'id');
    }

    public function produk()
    {
        return $this->belongsTo(MasterProduk::class, 'produk_id', 'id');
    }

    public function toko()
    {
        return $this->belongsTo(MasterToko::class, 'toko_id', 'id');
    }

    public function tempatProduk()
    {
        return $this->belongsTo(MasterTempatProduk::class, 'tempat_produk_id', 'id');
    }

    public function scopeByPenerimaan($query, $penerimaanId)
    {
        return $query->where('penerimaan_id', $penerimaanId);
    }

    public function scopeByProduk($query, $produkId)
    {
        return $query->where('produk_id', $produkId);
    }

    public function scopeByProdukToko($query, $produkTokoId)
    {
        return $query->where('produk_toko_id', $produkTokoId);
    }
}