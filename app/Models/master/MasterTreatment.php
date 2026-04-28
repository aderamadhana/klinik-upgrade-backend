<?php

namespace App\Models\Master;

class MasterTreatment extends BaseMasterModel
{
    protected $table = 'master_treatment';
    protected $primaryKey = 'id';

    public function tipeTreatment()
    {
        return $this->belongsTo(MasterTipeTreatment::class, 'tipe_treatment_id', 'id');
    }

    public function unitTreatment()
    {
        return $this->belongsTo(MasterUnitTreatment::class, 'unit_treatment_id', 'id');
    }

    public function hargaToko()
    {
        return $this->hasMany(MasterTreatmentToko::class, 'treatment_id', 'id');
    }
}