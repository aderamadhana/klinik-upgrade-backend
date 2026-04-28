<?php

namespace App\Models\Master;

class MasterUnitTreatment extends BaseMasterModel
{
    protected $table = 'master_unit_treatment';
    protected $primaryKey = 'id';

    public function treatment()
    {
        return $this->hasMany(MasterTreatment::class, 'unit_treatment_id', 'id');
    }
}