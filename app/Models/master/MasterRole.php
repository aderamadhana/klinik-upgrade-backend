<?php

namespace App\Models\Master;

class MasterRole extends BaseMasterModel
{
    protected $table = 'master_role';
    protected $primaryKey = 'id';

    public function users()
    {
        return $this->hasMany(MasterUser::class, 'role_id', 'id');
    }
}