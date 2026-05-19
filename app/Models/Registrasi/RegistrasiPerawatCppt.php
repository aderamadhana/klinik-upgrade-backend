<?php

namespace App\Models\Registrasi;

class RegistrasiPerawatCppt extends BaseRegistrasiModel
{
    protected $table = 'registrasi_perawat_cppt';

    const STATUS_DRAFT = 0;
    const STATUS_FINAL = 1;
    const STATUS_BATAL = 9;

    protected $casts = [
        'registrasi_id' => 'integer',
        'task_id' => 'integer',
        'dokter_id' => 'integer',
        'perawat_id' => 'integer',
        'tanggal_jam' => 'datetime',
        'subjective_category_id' => 'integer',
        'assessment_id' => 'integer',
        'status' => 'integer',
        'is_delete' => 'boolean',
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

    public function perawat()
    {
        return $this->belongsTo('App\Models\Master\MasterKaryawan', 'perawat_id');
    }
}