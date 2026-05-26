<?php

namespace App\Models;

use App\Models\Master\MasterAgama;
use App\Models\Master\MasterPekerjaan;
use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Model;

class Pasien extends Model
{
    use Auditable;
    protected $table = 'pasien';

    protected $guarded = [];

    public $timestamps = true;

    protected $casts = [
        'id' => 'integer',
        'tipe_pasien' => 'integer',
        'pekerjaan_id' => 'integer',
        'agama_id' => 'integer',
        'status_pernikahan' => 'integer',
        'tanggal_lahir' => 'date',
        'is_delete' => 'integer',
        'created_by' => 'integer',
        'updated_by' => 'integer',
    ];

    public function scopeActive($query)
    {
        return $query->where(function ($q) {
            $q->where('is_delete', 0)
              ->orWhereNull('is_delete');
        });
    }

    public function pekerjaan()
    {
        return $this->belongsTo(MasterPekerjaan::class, 'pekerjaan_id', 'id');
    }

    public function agama()
    {
        return $this->belongsTo(MasterAgama::class, 'agama_id', 'id');
    }

    public function getTipePasienTextAttribute()
    {
        return match ((int) $this->tipe_pasien) {
            1 => 'Pasien',
            2 => 'Non Pasien',
            default => null,
        };
    }

    public function getStatusPernikahanTextAttribute()
    {
        return match ((int) $this->status_pernikahan) {
            1 => 'Belum Menikah',
            2 => 'Menikah',
            3 => 'Cerai',
            default => null,
        };
    }

    public function getJenisKelaminTextAttribute()
    {
        return match ($this->jenis_kelamin) {
            'L' => 'Laki-laki',
            'P' => 'Perempuan',
            default => null,
        };
    }

    public function toko()
    {
        return $this->belongsTo(\App\Models\Master\MasterToko::class, 'toko_id', 'id');
    }
}