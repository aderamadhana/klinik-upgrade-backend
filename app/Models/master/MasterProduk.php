<?php

namespace App\Models\master;

class MasterProduk extends BaseMasterModel
{
    protected $table = 'master_produk';
    protected $primaryKey = 'id';

    public function kategori()
    {
        return $this->belongsTo(MasterKategoriProduk::class, 'kategori_produk_id', 'id');
    }

    public function golongan()
    {
        return $this->belongsTo(MasterGolonganProduk::class, 'golongan_produk_id', 'id');
    }

    public function satuan()
    {
        return $this->belongsTo(MasterSatuan::class, 'satuan_id', 'id');
    }

    public function tempatProduk()
    {
        return $this->belongsTo(MasterTempatProduk::class, 'tempat_produk_id', 'id');
    }

    public function hargaToko()
    {
        return $this->hasMany(MasterProdukToko::class, 'produk_id', 'id');
    }
}