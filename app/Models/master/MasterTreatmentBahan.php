<?php

namespace App\Models\Master;

use App\Models\Registrasi\RegistrasiPerawatBahanTreatmentDetail;

class MasterTreatmentBahan extends BaseMasterModel
{
    protected $table = 'master_treatment_bahan';

    protected $primaryKey = 'id';

    protected $guarded = ['id'];

    public $timestamps = false;

    protected $casts = [
        'id' => 'integer',
        'treatment_id' => 'integer',
        'produk_id' => 'integer',
        'qty_default' => 'decimal:4',
        'satuan_id' => 'integer',
        'is_required' => 'integer',
        'is_active' => 'integer',
        'is_delete' => 'integer',
        'sort_order' => 'integer',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    public function treatment()
    {
        return $this->belongsTo(MasterTreatment::class, 'treatment_id', 'id');
    }

    public function produk()
    {
        return $this->belongsTo(MasterProduk::class, 'produk_id', 'id');
    }

    public function satuan()
    {
        return $this->belongsTo(MasterSatuan::class, 'satuan_id', 'id');
    }

    public function registrasiPerawatBahanDetails()
    {
        return $this->hasMany(
            RegistrasiPerawatBahanTreatmentDetail::class,
            'produk_id',
            'produk_id'
        )->whereColumn('registrasi_perawat_bahan_treatment_detail.treatment_id', 'master_treatment_bahan.treatment_id');
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

    public function scopeRequired($query)
    {
        return $query->where('is_required', 1);
    }
}