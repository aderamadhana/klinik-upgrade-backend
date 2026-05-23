<?php

namespace App\Models\Stock;

use App\Models\Master\MasterProduk;
use App\Models\Master\MasterProdukToko;
use App\Models\Master\MasterTempatProduk;
use App\Models\Master\MasterToko;

class StockReservasiProduk extends BaseStockModel
{
    protected $table = 'stock_reservasi_produk';
    protected $primaryKey = 'id';

    protected $casts = [
        'toko_id' => 'integer',
        'tempat_produk_id' => 'integer',
        'produk_toko_id' => 'integer',
        'produk_id' => 'integer',

        'qty_reserved' => 'decimal:4',

        'source_id' => 'integer',
        'source_detail_id' => 'integer',
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

    public function scopeActiveReserve($query)
    {
        return $query->where('status', 'ACTIVE');
    }

    public function scopeConsumed($query)
    {
        return $query->where('status', 'CONSUMED');
    }

    public function scopeReleased($query)
    {
        return $query->where('status', 'RELEASED');
    }

    public function scopeExpired($query)
    {
        return $query->where('status', 'EXPIRED');
    }

    public function scopeCancelled($query)
    {
        return $query->where('status', 'CANCELLED');
    }

    public function scopeByToko($query, $tokoId)
    {
        return $query->where('toko_id', $tokoId);
    }

    public function scopeByTempat($query, $tempatProdukId)
    {
        return $query->where('tempat_produk_id', $tempatProdukId);
    }

    public function scopeByProduk($query, $produkId)
    {
        return $query->where('produk_id', $produkId);
    }

    public function scopeByProdukToko($query, $produkTokoId)
    {
        return $query->where('produk_toko_id', $produkTokoId);
    }

    public function scopeBySource($query, $sourceType, $sourceId, $sourceDetailId = null)
    {
        $query->where('source_type', $sourceType)
            ->where('source_id', $sourceId);

        if ($sourceDetailId !== null) {
            $query->where('source_detail_id', $sourceDetailId);
        }

        return $query;
    }

    public function scopeNeedExpired($query)
    {
        return $query->where('status', 'ACTIVE')
            ->whereNotNull('expired_at')
            ->where('expired_at', '<=', now());
    }
}