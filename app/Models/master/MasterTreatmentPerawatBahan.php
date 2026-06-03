<?php

namespace App\Models\Master;

class MasterTreatmentPerawatBahan extends BaseMasterModel
{
    protected $table = 'master_treatment_perawat_bahan';

    protected $primaryKey = 'id';

    protected $guarded = ['id'];

    public $timestamps = false;

    protected $casts = [
        'id' => 'integer',
        'treatment_id' => 'integer',
        'perawat_bahan_id' => 'integer',
        'jumlah_default' => 'decimal:4',
        'is_active' => 'integer',
        'is_delete' => 'integer',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    public function treatment()
    {
        return $this->belongsTo(MasterTreatment::class, 'treatment_id', 'id');
    }

    public function bahan()
    {
        return $this->belongsTo(MasterPerawatBahan::class, 'perawat_bahan_id', 'id');
    }

    public function scopeActive($query)
    {
        return $query
            ->where('is_active', 1)
            ->where('is_delete', 0);
    }

    public function scopeByTreatment($query, $treatmentId)
    {
        return $query->where('treatment_id', $treatmentId);
    }
}