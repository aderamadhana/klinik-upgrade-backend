<?php

namespace App\Models\Antrian;

use App\Models\Master\MasterToko;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class MasterAntrianCounter extends BaseAntrianModel
{
    protected $table = 'master_antrian_counter';

    protected $primaryKey = 'id';

    protected $casts = [
        'toko_id' => 'integer',
        'is_active' => 'boolean',
        'is_delete' => 'boolean',
        'created_by' => 'integer',
        'updated_by' => 'integer',
    ];

    public function toko(): BelongsTo
    {
        return $this->belongsTo(MasterToko::class, 'toko_id', 'id');
    }

    public function antrian(): HasMany
    {
        return $this->hasMany(Antrian::class, 'counter_id', 'id');
    }

    public function logs(): HasMany
    {
        return $this->hasMany(AntrianLog::class, 'counter_id', 'id');
    }

    public function scopeAktif($query)
    {
        return $query->where('is_active', 1)
            ->where(function ($q) {
                $q->where('is_delete', 0)
                    ->orWhereNull('is_delete');
            });
    }
}