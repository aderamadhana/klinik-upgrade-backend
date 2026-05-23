<?php

namespace App\Models\Stock;

use App\Models\Master\MasterProduk;
use App\Models\Master\MasterProdukToko;
use App\Models\Master\MasterTempatProduk;
use App\Models\Master\MasterToko;

class StockProdukToko extends BaseStockModel
{
    protected $table = 'stock_produk_toko';
    protected $primaryKey = 'id';

    protected $casts = [
        'produk_toko_id' => 'integer',
        'produk_id' => 'integer',
        'toko_id' => 'integer',
        'tempat_produk_id' => 'integer',

        'stok_awal' => 'decimal:4',
        'stok_masuk' => 'decimal:4',
        'stok_keluar' => 'decimal:4',
        'stok_penyesuaian' => 'decimal:4',
        'stok_akhir' => 'decimal:4',
        'stok_reserved' => 'decimal:4',
        'stok_minimum' => 'decimal:4',

        'harga_beli_terakhir' => 'decimal:2',
        'harga_jual_terakhir' => 'decimal:2',

        'is_delete' => 'integer',
    ];

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

    public function mutasi()
    {
        return $this->hasMany(StockMutasiProduk::class, 'produk_toko_id', 'produk_toko_id')
            ->whereColumn('stock_mutasi_produk.toko_id', 'stock_produk_toko.toko_id')
            ->whereColumn('stock_mutasi_produk.tempat_produk_id', 'stock_produk_toko.tempat_produk_id');
    }

    public function reservasiAktif()
    {
        return $this->hasMany(StockReservasiProduk::class, 'produk_toko_id', 'produk_toko_id')
            ->whereColumn('stock_reservasi_produk.toko_id', 'stock_produk_toko.toko_id')
            ->whereColumn('stock_reservasi_produk.tempat_produk_id', 'stock_produk_toko.tempat_produk_id')
            ->where('status', 'ACTIVE');
    }

    public function scopeByToko($query, $tokoId)
    {
        return $query->where('toko_id', $tokoId);
    }

    public function scopeByProduk($query, $produkId)
    {
        return $query->where('produk_id', $produkId);
    }

    public function scopeByProdukToko($query, $produkTokoId)
    {
        return $query->where('produk_toko_id', $produkTokoId);
    }

    public function scopeByTempat($query, $tempatProdukId)
    {
        return $query->where('tempat_produk_id', $tempatProdukId);
    }

    public function scopeStokMenipis($query)
    {
        return $query->whereColumn('stok_akhir', '<=', 'stok_minimum');
    }

    public function getStokTersediaAttribute()
    {
        return (float) $this->stok_akhir - (float) $this->stok_reserved;
    }
}