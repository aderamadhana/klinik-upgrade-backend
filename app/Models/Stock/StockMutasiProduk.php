<?php

namespace App\Models\Stock;

use App\Models\Master\MasterProduk;
use App\Models\Master\MasterProdukToko;
use App\Models\Master\MasterTempatProduk;
use App\Models\Master\MasterToko;

class StockMutasiProduk extends BaseStockModel
{
    protected $table = 'stock_mutasi_produk';
    protected $primaryKey = 'id';

    protected $casts = [
        'toko_id' => 'integer',
        'tempat_produk_id' => 'integer',
        'produk_toko_id' => 'integer',
        'produk_id' => 'integer',

        'qty_masuk' => 'decimal:4',
        'qty_keluar' => 'decimal:4',
        'qty_adjustment' => 'decimal:4',
        'qty_reserved_delta' => 'decimal:4',

        'stok_sebelum' => 'decimal:4',
        'stok_sesudah' => 'decimal:4',
        'reserved_sebelum' => 'decimal:4',
        'reserved_sesudah' => 'decimal:4',

        'harga_beli' => 'decimal:2',
        'harga_jual' => 'decimal:2',

        'ref_id' => 'integer',
        'ref_detail_id' => 'integer',

        'is_void' => 'integer',
        'void_ref_id' => 'integer',
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

    public function scopeByTipe($query, $tipeMutasi)
    {
        return $query->where('tipe_mutasi', $tipeMutasi);
    }

    public function scopeByPeriode($query, $tanggalAwal, $tanggalAkhir)
    {
        return $query->whereBetween('tanggal', [
            $tanggalAwal . ' 00:00:00',
            $tanggalAkhir . ' 23:59:59',
        ]);
    }

    public function scopeNotVoid($query)
    {
        return $query->where(function ($q) {
            $q->where('is_void', 0)
                ->orWhereNull('is_void');
        });
    }

    public function scopeByReference($query, $refType, $refId, $refDetailId = null)
    {
        $query->where('ref_type', $refType)
            ->where('ref_id', $refId);

        if ($refDetailId !== null) {
            $query->where('ref_detail_id', $refDetailId);
        }

        return $query;
    }
}