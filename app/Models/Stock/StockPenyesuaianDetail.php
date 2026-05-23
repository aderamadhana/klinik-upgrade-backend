<?php

namespace App\Models\Stock;

use App\Models\Master\MasterProduk;
use App\Models\Master\MasterProdukToko;
use App\Models\Master\MasterTempatProduk;
use App\Models\Master\MasterToko;

class StockPenyesuaianDetail extends BaseStockModel
{
    protected $table = 'stock_penyesuaian_detail';
    protected $primaryKey = 'id';

    protected $casts = [
        'penyesuaian_id' => 'integer',

        'toko_id' => 'integer',
        'tempat_produk_id' => 'integer',
        'produk_toko_id' => 'integer',
        'produk_id' => 'integer',

        'stok_sistem' => 'decimal:4',
        'stok_fisik' => 'decimal:4',
        'selisih' => 'decimal:4',

        'is_delete' => 'integer',
    ];

    public function penyesuaian()
    {
        return $this->belongsTo(StockPenyesuaian::class, 'penyesuaian_id', 'id');
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

    public function scopeByPenyesuaian($query, $penyesuaianId)
    {
        return $query->where('penyesuaian_id', $penyesuaianId);
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