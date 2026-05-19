<?php

namespace App\Models\Registrasi;

class RegistrasiPenjualanDetail extends BaseRegistrasiModel
{
    protected $table = 'registrasi_penjualan_detail';

    const SOURCE_FO = 1;
    const SOURCE_DOKTER_RESEP = 2;
    const SOURCE_KASIR = 3;

    const DISKON_TIDAK_ADA = 0;
    const DISKON_PERSEN = 1;
    const DISKON_RUPIAH = 2;

    protected $casts = [
        'registrasi_id' => 'integer',
        'source_type' => 'integer',
        'source_task_id' => 'integer',
        'source_resep_id' => 'integer',
        'source_karyawan_id' => 'integer',
        'produk_toko_id' => 'integer',
        'produk_id' => 'integer',
        'harga' => 'decimal:2',
        'jumlah' => 'integer',
        'diskon_tipe' => 'integer',
        'diskon_nilai' => 'decimal:2',
        'diskon_referral' => 'decimal:2',
        'subtotal' => 'decimal:2',
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

    public function sourceResep()
    {
        return $this->belongsTo(RegistrasiDokterResepDetail::class, 'source_resep_id');
    }

    public function sourceKaryawan()
    {
        return $this->belongsTo('App\Models\Master\MasterKaryawan', 'source_karyawan_id');
    }

    public function produkToko()
    {
        return $this->belongsTo('App\Models\Master\MasterProdukToko', 'produk_toko_id');
    }

    public function produk()
    {
        return $this->belongsTo('App\Models\Master\MasterProduk', 'produk_id');
    }
}