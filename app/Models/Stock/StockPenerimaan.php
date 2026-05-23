<?php

namespace App\Models\Stock;

use App\Models\Master\MasterSuplier;
use App\Models\Master\MasterTempatProduk;
use App\Models\Master\MasterToko;

class StockPenerimaan extends BaseStockModel
{
    protected $table = 'stock_penerimaan';
    protected $primaryKey = 'id';

    protected $casts = [
        'toko_id' => 'integer',
        'tempat_produk_id' => 'integer',
        'supplier_id' => 'integer',

        'total_qty' => 'decimal:4',
        'total_nominal' => 'decimal:2',

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

    public function supplier()
    {
        return $this->belongsTo(MasterSuplier::class, 'supplier_id', 'id');
    }

    public function details()
    {
        return $this->hasMany(StockPenerimaanDetail::class, 'penerimaan_id', 'id')
            ->active();
    }

    public function semuaDetails()
    {
        return $this->hasMany(StockPenerimaanDetail::class, 'penerimaan_id', 'id');
    }

    public function mutasi()
    {
        return $this->hasMany(StockMutasiProduk::class, 'ref_id', 'id')
            ->where('ref_type', 'PENERIMAAN');
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

    public function scopeByPeriode($query, $tanggalAwal, $tanggalAkhir)
    {
        return $query->whereBetween('tanggal', [$tanggalAwal, $tanggalAkhir]);
    }
}