<?php

namespace App\Models\Master;

class MasterSupplierToko extends BaseMasterModel
{
    protected $table = 'master_supplier_toko';
    protected $primaryKey = 'id';

    public function suplier()
    {
        return $this->belongsTo(MasterSuplier::class, 'suplier_id', 'id');
    }

    public function toko()
    {
        return $this->belongsTo(MasterToko::class, 'toko_id', 'id');
    }
}