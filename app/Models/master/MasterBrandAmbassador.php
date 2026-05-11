<?php

namespace App\Models\Master;

class MasterBrandAmbassador extends BaseMasterModel
{
    protected $table = 'master_brand_ambassador';
    protected $primaryKey = 'id';

    public function toko()
    {
        return $this->belongsTo(MasterToko::class, 'toko_id', 'id');
    }
}