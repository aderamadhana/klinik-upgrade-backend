<?php

namespace App\Models\Registrasi;

class RegistrasiTreatmentDetail extends BaseRegistrasiModel
{
    protected $table = 'registrasi_treatment_detail';

    public const SOURCE_FO = 1;
    public const SOURCE_DOKTER = 2;
    public const SOURCE_PERAWAT = 3;
    public const SOURCE_KASIR = 4;

    public const STATUS_BELUM_DIKERJAKAN = 0;
    public const STATUS_PROSES = 1;
    public const STATUS_SELESAI = 2;
    public const STATUS_BATAL = 9;

    protected $casts = [
        'registrasi_id' => 'integer',
        'source_type' => 'integer',
        'source_task_id' => 'integer',
        'source_karyawan_id' => 'integer',
        'perawat_id' => 'integer',
        'is_saran_dokter' => 'integer',
        'is_deposit_claim' => 'boolean',
        'deposit_treatment_id' => 'integer',
        'deposit_claim_id' => 'integer',
        'treatment_toko_id' => 'integer',
        'treatment_id' => 'integer',
        'harga' => 'decimal:2',
        'jumlah' => 'integer',
        'total' => 'decimal:2',
        'perlu_tindakan_perawat' => 'boolean',
        'status' => 'integer',
        'is_delete' => 'boolean',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    public function registrasi()
    {
        return $this->belongsTo(RegistrasiKunjungan::class, 'registrasi_id');
    }

    public function sourceTask()
    {
        return $this->belongsTo(RegistrasiTask::class, 'source_task_id');
    }

    public function sourceKaryawan()
    {
        return $this->belongsTo(
            'App\\Models\\Master\\MasterKaryawan',
            'source_karyawan_id'
        );
    }

    public function perawat()
    {
        return $this->belongsTo(
            'App\\Models\\Master\\MasterKaryawan',
            'perawat_id'
        );
    }

    public function treatmentToko()
    {
        return $this->belongsTo(
            'App\\Models\\Master\\MasterTreatmentToko',
            'treatment_toko_id'
        );
    }

    public function treatment()
    {
        return $this->belongsTo(
            'App\\Models\\Master\\MasterTreatment',
            'treatment_id'
        );
    }

    public function beforeAfterFotos()
    {
        return $this->hasMany(
            RegistrasiPerawatBeforeAfterFoto::class,
            'treatment_detail_id'
        );
    }

    public function bahanDetails()
    {
        return $this->hasMany(
            RegistrasiPerawatBahanTreatmentDetail::class,
            'treatment_detail_id'
        );
    }
}
