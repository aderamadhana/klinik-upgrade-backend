<?php

namespace App\Models\Master;

class MasterTreatmentToko extends BaseMasterModel
{
    protected $table = 'master_treatment_toko';
    protected $primaryKey = 'id';

    public function treatment()
    {
        return $this->belongsTo(MasterTreatment::class, 'treatment_id', 'id');
    }

    public function toko()
    {
        return $this->belongsTo(MasterToko::class, 'toko_id', 'id');
    }
}