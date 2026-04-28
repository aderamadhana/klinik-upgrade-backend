<?php

namespace App\Models\Master;

class MasterKategoriProduk extends BaseMasterModel
{
    protected $table = 'master_kategori_produk';
    protected $primaryKey = 'id';

    public function produk()
    {
        return $this->hasMany(MasterProduk::class, 'kategori_produk_id', 'id');
    }
}