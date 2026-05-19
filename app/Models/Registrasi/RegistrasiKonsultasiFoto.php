<?php

namespace App\Models\Registrasi;

class RegistrasiKonsultasiFoto extends BaseRegistrasiModel
{
    protected $table = 'registrasi_konsultasi_foto';

    const POSISI_KIRI = 1;
    const POSISI_DEPAN = 2;
    const POSISI_KANAN = 3;

    protected $casts = [
        'registrasi_id' => 'integer',
        'konsultasi_id' => 'integer',
        'posisi_foto' => 'integer',
        'file_size' => 'integer',
        'is_delete' => 'boolean',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    public function registrasi()
    {
        return $this->belongsTo(RegistrasiKunjungan::class, 'registrasi_id');
    }

    public function konsultasi()
    {
        return $this->belongsTo(RegistrasiKonsultasiIntake::class, 'konsultasi_id');
    }
}