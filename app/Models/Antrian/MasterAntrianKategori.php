<?php

namespace App\Models\Antrian;

use App\Models\Master\MasterToko;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class MasterAntrianKategori extends BaseAntrianModel
{
    protected $table = 'master_antrian_kategori';

    protected $primaryKey = 'id';

    protected $casts = [
        'toko_id' => 'integer',
        'is_priority' => 'boolean',
        'priority_level' => 'integer',
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
        return $this->hasMany(Antrian::class, 'kategori_id', 'id');
    }

    public function scopeAktif($query)
    {
        return $query->where('is_active', 1)
            ->where(function ($q) {
                $q->where('is_delete', 0)
                    ->orWhereNull('is_delete');
            });
    }

    public function scopePriority($query)
    {
        return $query->where('is_priority', 1);
    }

    public function scopeGlobalAtauToko($query, $tokoId)
    {
        return $query->where(function ($q) use ($tokoId) {
            $q->whereNull('toko_id');

            if ($tokoId) {
                $q->orWhere('toko_id', $tokoId);
            }
        });
    }
}