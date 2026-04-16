<?php

namespace App\Models\master;

class MasterToko extends BaseMasterModel
{
    protected $table = 'master_toko';
    protected $primaryKey = 'id';

    public function produkToko()
    {
        return $this->hasMany(MasterProdukToko::class, 'toko_id', 'id');
    }

    public function treatmentToko()
    {
        return $this->hasMany(MasterTreatmentToko::class, 'toko_id', 'id');
    }

    public function karyawanPenempatan()
    {
        return $this->hasMany(MasterKaryawanPenempatan::class, 'toko_id', 'id');
    }
}