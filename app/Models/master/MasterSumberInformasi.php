<?php

namespace App\Models\Master;

class MasterSumberInformasi extends BaseMasterModel
{
    protected $table = 'master_sumber_informasi';

    protected $primaryKey = 'id';

    public $incrementing = true;

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