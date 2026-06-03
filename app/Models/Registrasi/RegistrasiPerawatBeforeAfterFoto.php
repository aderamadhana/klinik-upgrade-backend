<?php

namespace App\Models\Registrasi;

class RegistrasiPerawatBeforeAfterFoto extends BaseRegistrasiModel
{
    const TAHAP_BEFORE = 1;
    const TAHAP_AFTER = 2;

    protected $table = 'registrasi_perawat_before_after_foto';

    protected $primaryKey = 'id';

    protected $guarded = ['id'];

    public $timestamps = false;

    protected $casts = [
        'id' => 'integer',
        'registrasi_id' => 'integer',
        'task_id' => 'integer',
        'treatment_detail_id' => 'integer',
        'tahap' => 'integer',
        'slot_no' => 'integer',
        'file_size' => 'integer',
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

    public function scopeByTask($query, $taskId)
    {
        return $query->where('task_id', $taskId);
    }

    public function scopeByTreatmentDetail($query, $treatmentDetailId)
    {
        return $query->where('treatment_detail_id', $treatmentDetailId);
    }

    public function scopeBefore($query)
    {
        return $query->where('tahap', self::TAHAP_BEFORE);
    }

    public function scopeAfter($query)
    {
        return $query->where('tahap', self::TAHAP_AFTER);
    }

    public function scopeBySlot($query, $slotNo)
    {
        return $query->where('slot_no', $slotNo);
    }

    public function getTahapLabelAttribute()
    {
        return (int) $this->tahap === self::TAHAP_BEFORE ? 'Before' : 'After';
    }
}