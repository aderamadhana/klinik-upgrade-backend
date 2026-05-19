<?php

namespace App\Models\Registrasi;

class RegistrasiTask extends BaseRegistrasiModel
{
    protected $table = 'registrasi_task';

    protected $hasDeleteFlag = false;

    const TYPE_KONSULTASI = 1;
    const TYPE_TREATMENT = 2;
    const TYPE_TINDAKAN_PERAWAT = 3;
    const TYPE_PEMBAYARAN = 4;

    const STATUS_MENUNGGU = 0;
    const STATUS_PROSES = 1;
    const STATUS_SELESAI = 2;
    const STATUS_BATAL = 9;

    protected $casts = [
        'registrasi_id' => 'integer',
        'task_type' => 'integer',
        'assigned_karyawan_id' => 'integer',
        'task_order' => 'integer',
        'status' => 'integer',
        'started_at' => 'datetime',
        'finished_at' => 'datetime',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    public function registrasi()
    {
        return $this->belongsTo(RegistrasiKunjungan::class, 'registrasi_id');
    }

    public function assignedKaryawan()
    {
        return $this->belongsTo('App\Models\Master\MasterKaryawan', 'assigned_karyawan_id');
    }

    public function dokterSoap()
    {
        return $this->hasOne(RegistrasiDokterSoap::class, 'task_id');
    }

    public function treatmentDetails()
    {
        return $this->hasMany(RegistrasiTreatmentDetail::class, 'source_task_id');
    }

    public function perawatCppts()
    {
        return $this->hasMany(RegistrasiPerawatCppt::class, 'task_id');
    }

    public function beforeAfterFotos()
    {
        return $this->hasMany(RegistrasiPerawatBeforeAfterFoto::class, 'task_id');
    }

    public function bahanTreatmentDetails()
    {
        return $this->hasMany(RegistrasiPerawatBahanTreatmentDetail::class, 'task_id');
    }
}