<?php

namespace App\Models\Registrasi;

class RegistrasiKonsultasiIntake extends BaseRegistrasiModel
{
    protected $table = 'registrasi_konsultasi_intake';

    protected $hasDeleteFlag = false;

    const JENIS_OFFLINE = 1;
    const JENIS_ONLINE = 2;

    const STATUS_MENUNGGU = 0;
    const STATUS_PROSES = 1;
    const STATUS_SELESAI = 2;
    const STATUS_BATAL = 9;

    protected $casts = [
        'registrasi_id' => 'integer',
        'request_dokter_id' => 'integer',
        'sedang_hamil' => 'boolean',
        'sedang_menyusui' => 'boolean',
        'jenis_konsultasi' => 'integer',
        'status' => 'integer',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    public function registrasi()
    {
        return $this->belongsTo(RegistrasiKunjungan::class, 'registrasi_id');
    }

    public function requestDokter()
    {
        return $this->belongsTo('App\Models\Master\MasterKaryawan', 'request_dokter_id');
    }

    public function fotos()
    {
        return $this->hasMany(RegistrasiKonsultasiFoto::class, 'registrasi_id', 'registrasi_id');
    }
}