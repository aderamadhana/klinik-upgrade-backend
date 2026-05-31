<?php

namespace App\Models\Master;

class MasterJenisTransaksi extends BaseMasterModel
{
    protected $table = 'master_jenis_transaksi';

    protected $primaryKey = 'id';

    public $incrementing = false;

    protected $keyType = 'int';

    public $timestamps = true;

    protected $guarded = [];

    protected $casts = [
        'id' => 'integer',
        'sort_order' => 'integer',
        'is_active' => 'integer',
        'is_delete' => 'integer',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    public function scopeActive($query)
    {
        return $query
            ->where(function ($q) {
                $q->where('is_delete', 0)
                    ->orWhereNull('is_delete');
            })
            ->where(function ($q) {
                $q->where('is_active', 1)
                    ->orWhereNull('is_active');
            });
    }
}