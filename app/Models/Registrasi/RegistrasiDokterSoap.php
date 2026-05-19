<?php

namespace App\Models\Registrasi;

class RegistrasiDokterSoap extends BaseRegistrasiModel
{
    protected $table = 'registrasi_dokter_soap';

    protected $hasDeleteFlag = false;

    const STATUS_DRAFT = 0;
    const STATUS_FINAL = 1;
    const STATUS_BATAL = 9;

    protected $casts = [
        'registrasi_id' => 'integer',
        'task_id' => 'integer',
        'dokter_id' => 'integer',
        'subjective_id' => 'integer',
        'diagnosa_id' => 'integer',
        'next_konsultasi_date' => 'date:Y-m-d',
        'status' => 'integer',
        'finalized_at' => 'datetime',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    public function registrasi()
    {
        return $this->belongsTo(RegistrasiKunjungan::class, 'registrasi_id');
    }

    public function task()
    {
        return $this->belongsTo(RegistrasiTask::class, 'task_id');
    }

    public function dokter()
    {
        return $this->belongsTo('App\Models\Master\MasterKaryawan', 'dokter_id');
    }

    public function resepDetails()
    {
        return $this->hasMany(RegistrasiDokterResepDetail::class, 'soap_id');
    }
}