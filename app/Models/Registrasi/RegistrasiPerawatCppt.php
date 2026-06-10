<?php

namespace App\Models\Registrasi;

use App\Models\Master\MasterAssessment;
use App\Models\Master\MasterKaryawan;
use App\Models\Master\MasterSubjective;

class RegistrasiPerawatCppt extends BaseRegistrasiModel
{
    public const STATUS_DRAFT = 0;
    public const STATUS_FINAL = 1;
    public const STATUS_BATAL = 9;

    protected $table = 'registrasi_perawat_cppt';

    protected $primaryKey = 'id';

    protected $guarded = ['id'];

    protected $with = [
        'subjectives',
        'assessments',
    ];

    protected $appends = [
        'subjective_ids',
        'assessment_ids',
    ];

    public $timestamps = false;

    protected $casts = [
        'id' => 'integer',
        'registrasi_id' => 'integer',
        'task_id' => 'integer',
        'dokter_id' => 'integer',
        'perawat_id' => 'integer',
        'tanggal_pengisian' => 'datetime',
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

    public function dokter()
    {
        return $this->belongsTo(MasterKaryawan::class, 'dokter_id', 'id');
    }

    public function perawat()
    {
        return $this->belongsTo(MasterKaryawan::class, 'perawat_id', 'id');
    }

    public function subjectives()
    {
        return $this->belongsToMany(
            MasterSubjective::class,
            'registrasi_perawat_cppt_subjective',
            'cppt_id',
            'subjective_id'
        )
            ->withPivot(['sort_order', 'created_at'])
            ->orderBy('registrasi_perawat_cppt_subjective.sort_order')
            ->orderBy('registrasi_perawat_cppt_subjective.id');
    }

    public function assessments()
    {
        return $this->belongsToMany(
            MasterAssessment::class,
            'registrasi_perawat_cppt_assessment',
            'cppt_id',
            'assessment_id'
        )
            ->withPivot(['sort_order', 'created_at'])
            ->orderBy('registrasi_perawat_cppt_assessment.sort_order')
            ->orderBy('registrasi_perawat_cppt_assessment.id');
    }

    public function getSubjectiveIdsAttribute(): array
    {
        return $this->subjectives
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->values()
            ->all();
    }

    public function getAssessmentIdsAttribute(): array
    {
        return $this->assessments
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->values()
            ->all();
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
        $this->tanggal_pengisian = $this->tanggal_pengisian ?: now();
        $this->updated_at = now();

        if ($updatedBy !== null) {
            $this->updated_by = $updatedBy;
        }

        return $this->save();
    }

    public function markCancelled($updatedBy = null)
    {
        $this->status = self::STATUS_BATAL;
        $this->updated_at = now();

        if ($updatedBy !== null) {
            $this->updated_by = $updatedBy;
        }

        return $this->save();
    }
}
