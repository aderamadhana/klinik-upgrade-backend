<?php

namespace App\Models\master;

class MasterSuplier extends BaseMasterModel
{
    protected $table = 'master_suplier';
    protected $primaryKey = 'id';

    public function toko()
    {
        return $this->hasMany(MasterSupplierToko::class, 'suplier_id', 'id');
    }
}