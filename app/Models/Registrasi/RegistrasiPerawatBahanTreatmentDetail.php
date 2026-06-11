<?php

namespace App\Models\Registrasi;

use App\Models\Master\MasterKaryawan;
use App\Models\Master\MasterTreatment;

class RegistrasiPerawatBahanTreatmentDetail extends BaseRegistrasiModel
{
    public const STATUS_BELUM_DIISI = 0;
    public const STATUS_SUDAH_DIISI = 1;
    public const STATUS_BATAL = 9;

    protected $table = 'registrasi_perawat_bahan_treatment_detail';
    protected $primaryKey = 'id';
    protected $guarded = ['id'];
    public $timestamps = false;

    protected $casts = [
        'id' => 'integer',
        'registrasi_id' => 'integer',
        'task_id' => 'integer',
        'treatment_detail_id' => 'integer',
        'master_treatment_perawat_bahan_id' => 'integer',
        'treatment_id' => 'integer',
        'perawat_bahan_id' => 'integer',
        'jumlah_default' => 'decimal:4',
        'jumlah_terpakai' => 'decimal:4',
        'tanggal_pengisian' => 'datetime',
        'toko_id' => 'integer',
        'perawat_id' => 'integer',
        'status' => 'integer',
        'is_delete' => 'boolean',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    public function registrasi()
    {
        return $this->belongsTo(RegistrasiKunjungan::class, 'registrasi_id', 'id');
    }

    public function task()
    {
        return $this->belongsTo(RegistrasiTask::class, 'task_id', 'id');
    }

    public function treatmentDetail()
    {
        return $this->belongsTo(RegistrasiTreatmentDetail::class, 'treatment_detail_id', 'id');
    }

    public function treatment()
    {
        return $this->belongsTo(MasterTreatment::class, 'treatment_id', 'id');
    }

    public function perawat()
    {
        return $this->belongsTo(MasterKaryawan::class, 'perawat_id', 'id');
    }

    public function scopeByTask($query, $taskId)
    {
        return $query->where('task_id', $taskId);
    }

    public function scopeByTreatmentDetail($query, $treatmentDetailId)
    {
        return $query->where('treatment_detail_id', $treatmentDetailId);
    }

    public function scopeBelumDiisi($query)
    {
        return $query->where('status', self::STATUS_BELUM_DIISI);
    }

    public function scopeSudahDiisi($query)
    {
        return $query->where('status', self::STATUS_SUDAH_DIISI);
    }

    public function markFilled($updatedBy = null)
    {
        $this->status = self::STATUS_SUDAH_DIISI;

        if ($updatedBy !== null) {
            $this->updated_by = $updatedBy;
        }

        return $this->save();
    }

    public function markCancelled($updatedBy = null)
    {
        $this->status = self::STATUS_BATAL;

        if ($updatedBy !== null) {
            $this->updated_by = $updatedBy;
        }

        return $this->save();
    }
}
