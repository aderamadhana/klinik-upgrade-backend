<?php

namespace App\Models\master;

class MasterTipeTreatment extends BaseMasterModel
{
    protected $table = 'master_tipe_treatment';
    protected $primaryKey = 'id';

    public function treatment()
    {
        return $this->hasMany(MasterTreatment::class, 'tipe_treatment_id', 'id');
    }
}