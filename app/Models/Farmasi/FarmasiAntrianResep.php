<?php

namespace App\Models\Farmasi;

use App\Models\Master\MasterKaryawan;
use App\Models\Master\MasterToko;
use App\Models\Pembayaran\PembayaranInvoice;
use App\Models\Registrasi\RegistrasiKunjungan;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class FarmasiAntrianResep extends Model
{
    use HasFactory;

    protected $table = 'farmasi_antrian_resep';

    protected $guarded = [];

    public const STATUS_MENUNGGU = 0;
    public const STATUS_PROSES = 1;
    public const STATUS_SELESAI = 2;
    public const STATUS_BATAL = 9;

    protected $casts = [
        'pembayaran_id' => 'integer',
        'registrasi_id' => 'integer',
        'toko_id' => 'integer',
        'status' => 'integer',
        'petugas_karyawan_id' => 'integer',
        'started_at' => 'datetime',
        'finished_at' => 'datetime',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    public function invoice()
    {
        return $this->belongsTo(PembayaranInvoice::class, 'pembayaran_id');
    }

    public function registrasi()
    {
        return $this->belongsTo(RegistrasiKunjungan::class, 'registrasi_id');
    }

    public function toko()
    {
        return $this->belongsTo(MasterToko::class, 'toko_id');
    }

    public function petugas()
    {
        return $this->belongsTo(MasterKaryawan::class, 'petugas_karyawan_id');
    }
}
