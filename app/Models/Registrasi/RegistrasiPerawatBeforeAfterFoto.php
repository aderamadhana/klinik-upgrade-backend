<?php

namespace App\Models\Registrasi;

class RegistrasiPerawatBeforeAfterFoto extends BaseRegistrasiModel
{
    protected $table = 'registrasi_perawat_before_after_foto';

    const TAHAP_BEFORE = 1;
    const TAHAP_AFTER = 2;

    protected $casts = [
        'registrasi_id' => 'integer',
        'task_id' => 'integer',
        'treatment_detail_id' => 'integer',
        'tahap' => 'integer',
        'slot_no' => 'integer',
        'file_size' => 'integer',
        'is_delete' => 'boolean',
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

    public function treatmentDetail()
    {
        return $this->belongsTo(RegistrasiTreatmentDetail::class, 'treatment_detail_id');
    }
}