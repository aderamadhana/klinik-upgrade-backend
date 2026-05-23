<?php

namespace App\Models\Stock;

use App\Models\Master\MasterTempatProduk;
use App\Models\Master\MasterToko;

class StockPenyesuaian extends BaseStockModel
{
    protected $table = 'stock_penyesuaian';
    protected $primaryKey = 'id';

    protected $casts = [
        'toko_id' => 'integer',
        'tempat_produk_id' => 'integer',
        'is_delete' => 'integer',
    ];

    public function toko()
    {
        return $this->belongsTo(MasterToko::class, 'toko_id', 'id');
    }

    public function tempatProduk()
    {
        return $this->belongsTo(MasterTempatProduk::class, 'tempat_produk_id', 'id');
    }

    public function details()
    {
        return $this->hasMany(StockPenyesuaianDetail::class, 'penyesuaian_id', 'id')
            ->active();
    }

    public function semuaDetails()
    {
        return $this->hasMany(StockPenyesuaianDetail::class, 'penyesuaian_id', 'id');
    }

    public function mutasi()
    {
        return $this->hasMany(StockMutasiProduk::class, 'ref_id', 'id')
            ->where('ref_type', 'PENYESUAIAN');
    }

    public function scopeDraft($query)
    {
        return $query->where('status', 'DRAFT');
    }

    public function scopePosted($query)
    {
        return $query->where('status', 'POSTED');
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

    public function scopeByJenis($query, $jenisPenyesuaian)
    {
        return $query->where('jenis_penyesuaian', $jenisPenyesuaian);
    }

    public function scopeByPeriode($query, $tanggalAwal, $tanggalAkhir)
    {
        return $query->whereBetween('tanggal', [$tanggalAwal, $tanggalAkhir]);
    }
}