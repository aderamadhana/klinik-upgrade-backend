<?php

namespace App\Models\Registrasi;

class RegistrasiPerawatBahanTreatmentDetail extends BaseRegistrasiModel
{
    protected $table = 'registrasi_perawat_bahan_treatment_detail';

    const STATUS_BELUM_DIISI = 0;
    const STATUS_SUDAH_DIISI = 1;
    const STATUS_BATAL = 9;

    protected $casts = [
        'registrasi_id' => 'integer',
        'task_id' => 'integer',
        'treatment_detail_id' => 'integer',
        'treatment_id' => 'integer',
        'treatment_toko_id' => 'integer',
        'produk_id' => 'integer',
        'produk_toko_id' => 'integer',
        'qty_default' => 'decimal:4',
        'qty_pakai' => 'decimal:4',
        'satuan_id' => 'integer',
        'status' => 'integer',
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

    public function treatment()
    {
        return $this->belongsTo('App\Models\Master\MasterTreatment', 'treatment_id');
    }

    public function treatmentToko()
    {
        return $this->belongsTo('App\Models\Master\MasterTreatmentToko', 'treatment_toko_id');
    }

    public function produk()
    {
        return $this->belongsTo('App\Models\Master\MasterProduk', 'produk_id');
    }

    public function produkToko()
    {
        return $this->belongsTo('App\Models\Master\MasterProdukToko', 'produk_toko_id');
    }

    public function satuan()
    {
        return $this->belongsTo('App\Models\Master\MasterSatuan', 'satuan_id');
    }
}