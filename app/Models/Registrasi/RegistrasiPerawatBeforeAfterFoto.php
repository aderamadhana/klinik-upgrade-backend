<?php

namespace App\Models\Registrasi;

class RegistrasiPerawatBeforeAfterFoto extends BaseRegistrasiModel
{
    public const TIPE_BEFORE = 'before';
    public const TIPE_AFTER = 'after';

    // Alias dipertahankan agar pemanggilan lama tidak langsung rusak.
    public const TAHAP_BEFORE = self::TIPE_BEFORE;
    public const TAHAP_AFTER = self::TIPE_AFTER;

    protected $table = 'registrasi_perawat_before_after_foto';
    protected $primaryKey = 'id';
    protected $guarded = ['id'];
    public $timestamps = false;

    protected $casts = [
        'id' => 'integer',
        'registrasi_id' => 'integer',
        'task_id' => 'integer',
        'treatment_detail_id' => 'integer',
        'urutan' => 'integer',
        'tanggal_upload' => 'datetime',
        'uploaded_by' => 'integer',
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
        return $this->belongsTo(
            RegistrasiTreatmentDetail::class,
            'treatment_detail_id',
            'id',
        );
    }

    public function scopeBefore($query)
    {
        return $query->where('tipe_foto', self::TIPE_BEFORE);
    }

    public function scopeAfter($query)
    {
        return $query->where('tipe_foto', self::TIPE_AFTER);
    }

    public function scopeByType($query, string $type)
    {
        return $query->where('tipe_foto', $type);
    }

    public function scopeByOrder($query, int $order)
    {
        return $query->where('urutan', $order);
    }

    public function scopeBySlot($query, int $slotNo)
    {
        return $this->scopeByOrder($query, $slotNo);
    }

    public function getTipeFotoLabelAttribute(): string
    {
        return $this->tipe_foto === self::TIPE_BEFORE ? 'Before' : 'After';
    }

    public function getTahapLabelAttribute(): string
    {
        return $this->tipe_foto_label;
    }
}
