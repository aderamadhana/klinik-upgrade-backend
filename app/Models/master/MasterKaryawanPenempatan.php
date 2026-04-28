<?php

namespace App\Models\Master;

class MasterKaryawanPenempatan extends BaseMasterModel
{
    protected $table = 'master_karyawan_penempatan';
    protected $primaryKey = 'id';

    public function karyawan()
    {
        return $this->belongsTo(MasterKaryawan::class, 'karyawan_id', 'id');
    }

    public function toko()
    {
        return $this->belongsTo(MasterToko::class, 'toko_id', 'id');
    }
}