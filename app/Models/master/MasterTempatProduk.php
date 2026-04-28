<?php

namespace App\Models\Master;

class MasterTempatProduk extends BaseMasterModel
{
    protected $table = 'master_tempat_produk';
    protected $primaryKey = 'id';

    public function produk()
    {
        return $this->hasMany(MasterProduk::class, 'tempat_produk_id', 'id');
    }
}