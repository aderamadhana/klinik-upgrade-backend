<?php

namespace App\Models\Registrasi;

use App\Models\Master\MasterTreatment;
use Illuminate\Database\Eloquent\Model;

class RegistrasiPerawatBahanTreatmentDetail extends Model
{
    protected $table = 'registrasi_perawat_bahan_treatment_detail';

    protected $guarded = [];

    protected $casts = [
        'registrasi_id' => 'integer',
        'task_id' => 'integer',
        'treatment_detail_id' => 'integer',
        'master_treatment_perawat_bahan_id' => 'integer',
        'treatment_id' => 'integer',
        'perawat_bahan_id' => 'integer',
        'jumlah_default' => 'decimal:2',
        'jumlah_terpakai' => 'decimal:2',
        'tanggal_pengisian' => 'date',
        'toko_id' => 'integer',
        'perawat_id' => 'integer',
        'status' => 'integer',
        'is_delete' => 'integer',
    ];

    public function scopeActive($query)
    {
        return $query->where(function ($q) {
            $q->whereNull($this->getTable() . '.is_delete')
                ->orWhere($this->getTable() . '.is_delete', 0);
        });
    }

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
        return $this->belongsTo(MasterTreatment::class, 'treatment_id');
    }
}
