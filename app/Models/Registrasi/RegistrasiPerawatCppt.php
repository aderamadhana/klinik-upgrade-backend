<?php

namespace App\Models\Registrasi;

use App\Models\Master\MasterAssessment;
use App\Models\Master\MasterKaryawan;
use App\Models\Master\MasterSubjective;

class RegistrasiPerawatCppt extends BaseRegistrasiModel
{
    const STATUS_DRAFT = 0;
    const STATUS_FINAL = 1;
    const STATUS_BATAL = 9;

    protected $table = 'registrasi_perawat_cppt';

    protected $primaryKey = 'id';

    protected $guarded = ['id'];

    public $timestamps = false;

    protected $casts = [
        'id' => 'integer',
        'registrasi_id' => 'integer',
        'task_id' => 'integer',
        'dokter_id' => 'integer',
        'perawat_id' => 'integer',
        'tanggal_jam' => 'datetime',
        'subjective_category_id' => 'integer',
        'assessment_id' => 'integer',
        'status' => 'integer',
        'finalized_at' => 'datetime',
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

    public function dokter()
    {
        return $this->belongsTo(MasterKaryawan::class, 'dokter_id', 'id');
    }

    public function perawat()
    {
        return $this->belongsTo(MasterKaryawan::class, 'perawat_id', 'id');
    }

    public function subjectiveCategory()
    {
        return $this->belongsTo(MasterSubjective::class, 'subjective_category_id', 'id');
    }

    public function assessment()
    {
        return $this->belongsTo(MasterAssessment::class, 'assessment_id', 'id');
    }

    public function scopeByTask($query, $taskId)
    {
        return $query->where('task_id', $taskId);
    }

    public function scopeDraft($query)
    {
        return $query->where('status', self::STATUS_DRAFT);
    }

    public function scopeFinal($query)
    {
        return $query->where('status', self::STATUS_FINAL);
    }

    public function markFinal($updatedBy = null)
    {
        $this->status = self::STATUS_FINAL;
        $this->finalized_at = now();

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