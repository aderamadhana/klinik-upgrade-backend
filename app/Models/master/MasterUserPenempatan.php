<?php

namespace App\Models\Master;

class MasterUserPenempatan extends BaseMasterModel
{
    protected $table = 'master_user_penempatan';
    protected $primaryKey = 'id';

    public function user()
    {
        return $this->hasMany(MasterUser::class, 'user_id', 'id');
    }
    
    public function toko()
    {
        return $this->belongsTo(MasterToko::class, 'toko_id', 'id');
    }
}