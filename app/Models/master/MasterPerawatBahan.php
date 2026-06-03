<?php

namespace App\Models\master;

class MasterPerawatBahan extends BaseMasterModel
{
    protected $table = 'master_perawat_bahan';

    protected $primaryKey = 'id';

    protected $guarded = ['id'];

    public $timestamps = false;

    protected $casts = [
        'id' => 'integer',
        'is_active' => 'integer',
        'is_delete' => 'integer',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    public function scopeActive($query)
    {
        return $query
            ->where('is_active', 1)
            ->where('is_delete', 0);
    }
}