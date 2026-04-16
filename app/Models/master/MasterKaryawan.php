<?php

namespace App\Models\master;

class MasterKaryawan extends BaseMasterModel
{
    protected $table = 'master_karyawan';
    protected $primaryKey = 'id';

    public function jabatan()
    {
        return $this->belongsTo(MasterJabatan::class, 'jabatan_id', 'id');
    }

    public function penempatan()
    {
        return $this->hasMany(MasterKaryawanPenempatan::class, 'karyawan_id', 'id');
    }
}