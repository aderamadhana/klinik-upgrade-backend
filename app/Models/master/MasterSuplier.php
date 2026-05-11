<?php

namespace App\Models\Master;

class MasterSuplier extends BaseMasterModel
{
    protected $table = 'master_supplier';
    protected $primaryKey = 'id';

    public function toko()
    {
        return $this->hasMany(MasterSupplierToko::class, 'suplier_id', 'id');
    }

    public function tokoAktif()
    {
        return $this->hasMany(MasterSupplierToko::class, 'supplier_id', 'id')
            ->active();
    }
}