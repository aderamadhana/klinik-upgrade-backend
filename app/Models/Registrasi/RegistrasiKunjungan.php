<?php

namespace App\Models\Registrasi;

class RegistrasiKunjungan extends BaseRegistrasiModel
{
    protected $table = 'registrasi_kunjungan';

    const STATUS_DRAFT = 0;
    const STATUS_AKTIF = 1;
    const STATUS_SELESAI = 2;
    const STATUS_BATAL = 9;

    const CHANNEL_TIDAK_KONSULTASI = 0;
    const CHANNEL_OFFLINE = 1;
    const CHANNEL_ONLINE = 2;

    const TASK_DRAFT = 0;
    const TASK_KONSULTASI = 1;
    const TASK_TREATMENT = 2;
    const TASK_PERAWAT = 3;
    const TASK_PEMBAYARAN = 4;

    protected $casts = [
        'toko_id' => 'integer',
        'pasien_id' => 'integer',
        'tanggal_kunjungan' => 'date:Y-m-d',
        'registered_at' => 'datetime',
        'dokter_awal_id' => 'integer',
        'perawat_awal_id' => 'integer',
        'channel_konsultasi' => 'integer',
        'is_treatment' => 'boolean',
        'is_penjualan' => 'boolean',
        'perlu_tindakan_perawat' => 'integer',
        'current_task' => 'integer',
        'status' => 'integer',
        'total_treatment' => 'decimal:2',
        'total_penjualan' => 'decimal:2',
        'grand_total' => 'decimal:2',
        'is_delete' => 'boolean',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
        'has_saran_dokter' => 'integer',
    ];

    public function toko()
    {
        return $this->belongsTo('App\Models\Master\MasterToko', 'toko_id');
    }

    public function pasien()
    {
        return $this->belongsTo('App\Models\Pasien', 'pasien_id');
    }

    public function dokterAwal()
    {
        return $this->belongsTo('App\Models\Master\MasterKaryawan', 'dokter_awal_id');
    }

    public function perawatAwal()
    {
        return $this->belongsTo('App\Models\Master\MasterKaryawan', 'perawat_awal_id');
    }

    public function tasks()
    {
        return $this->hasMany(RegistrasiTask::class, 'registrasi_id');
    }

    public function konsultasiIntake()
    {
        return $this->hasOne(RegistrasiKonsultasiIntake::class, 'registrasi_id');
    }

    public function konsultasiFotos()
    {
        return $this->hasMany(RegistrasiKonsultasiFoto::class, 'registrasi_id');
    }

    public function dokterSoap()
    {
        return $this->hasOne(RegistrasiDokterSoap::class, 'registrasi_id');
    }

    public function resepDetails()
    {
        return $this->hasMany(RegistrasiDokterResepDetail::class, 'registrasi_id');
    }

    public function treatmentDetails()
    {
        return $this->hasMany(RegistrasiTreatmentDetail::class, 'registrasi_id');
    }

    public function penjualanDetails()
    {
        return $this->hasMany(RegistrasiPenjualanDetail::class, 'registrasi_id');
    }

    public function perawatCppts()
    {
        return $this->hasMany(RegistrasiPerawatCppt::class, 'registrasi_id');
    }

    public function beforeAfterFotos()
    {
        return $this->hasMany(RegistrasiPerawatBeforeAfterFoto::class, 'registrasi_id');
    }

    public function bahanTreatmentDetails()
    {
        return $this->hasMany(RegistrasiPerawatBahanTreatmentDetail::class, 'registrasi_id');
    }
}