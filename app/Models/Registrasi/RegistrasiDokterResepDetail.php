<?php

namespace App\Models\Registrasi;

class RegistrasiDokterResepDetail extends BaseRegistrasiModel
{
    protected $table = 'registrasi_dokter_resep_detail';

    const STATUS_DIRESEPKAN = 0;
    const STATUS_DIBELI = 1;
    const STATUS_TIDAK_DIBELI = 2;
    const STATUS_BATAL = 9;

    protected $casts = [
        'registrasi_id' => 'integer',
        'soap_id' => 'integer',
        'produk_toko_id' => 'integer',
        'produk_id' => 'integer',
        'harga' => 'decimal:2',
        'jumlah' => 'integer',
        'total' => 'decimal:2',
        'status' => 'integer',
        'is_delete' => 'boolean',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    public function registrasi()
    {
        return $this->belongsTo(RegistrasiKunjungan::class, 'registrasi_id');
    }

    public function soap()
    {
        return $this->belongsTo(RegistrasiDokterSoap::class, 'soap_id');
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