<?php

namespace App\Models\master;

class MasterSatuan extends BaseMasterModel
{
    protected $table = 'master_satuan';
    protected $primaryKey = 'id';

    public function produk()
    {
        return $this->hasMany(MasterProduk::class, 'satuan_id', 'id');
    }
}