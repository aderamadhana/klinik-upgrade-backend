<?php

namespace App\Models\Registrasi;

use App\Models\Master\MasterProduk;
use App\Models\Master\MasterProdukToko;
use App\Models\Master\MasterSatuan;
use App\Models\Master\MasterTreatment;
use App\Models\Master\MasterTreatmentToko;

class RegistrasiPerawatBahanTreatmentDetail extends BaseRegistrasiModel
{
    const STATUS_BELUM_DIISI = 0;
    const STATUS_SUDAH_DIISI = 1;
    const STATUS_BATAL = 9;

    protected $table = 'registrasi_perawat_bahan_treatment_detail';

    protected $primaryKey = 'id';

    protected $guarded = ['id'];

    public $timestamps = false;

    protected $casts = [
        'id' => 'integer',
        'registrasi_id' => 'integer',
        'task_id' => 'integer',
        'treatment_detail_id' => 'integer',
        'treatment_id' => 'integer',
        'treatment_toko_id' => 'integer',
        'produk_id' => 'integer',
        'produk_toko_id' => 'integer',
        'qty_default' => 'decimal:4',
        'qty_pakai' => 'decimal:4',
        'satuan_id' => 'integer',
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

    public function treatmentToko()
    {
        return $this->belongsTo(MasterTreatmentToko::class, 'treatment_toko_id', 'id');
    }

    public function produk()
    {
        return $this->belongsTo(MasterProduk::class, 'produk_id', 'id');
    }

    public function produkToko()
    {
        return $this->belongsTo(MasterProdukToko::class, 'produk_toko_id', 'id');
    }

    public function satuan()
    {
        return $this->belongsTo(MasterSatuan::class, 'satuan_id', 'id');
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