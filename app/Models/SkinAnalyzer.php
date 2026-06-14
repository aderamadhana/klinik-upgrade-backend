<?php

namespace App\Models;

use App\Models\Master\MasterToko;
use App\Models\Master\MasterUser;
use Illuminate\Database\Eloquent\Model;

class SkinAnalyzer extends Model
{
    protected $table = 'skin_analyzer';

    protected $guarded = [];

    public $timestamps = true;

    protected $casts = [
        'id' => 'integer',
        'pasien_id' => 'integer',
        'toko_id' => 'integer',
        'is_delete' => 'integer',
        'created_by' => 'integer',
        'updated_by' => 'integer',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    public function scopeActive($query)
    {
        return $query->where(function ($builder) {
            $builder->where('is_delete', 0)
                ->orWhereNull('is_delete');
        });
    }

    public function pasien()
    {
        return $this->belongsTo(Pasien::class, 'pasien_id', 'id');
    }

    public function toko()
    {
        return $this->belongsTo(MasterToko::class, 'toko_id', 'id');
    }

    public function creator()
    {
        return $this->belongsTo(MasterUser::class, 'created_by', 'id');
    }

    public function updater()
    {
        return $this->belongsTo(MasterUser::class, 'updated_by', 'id');
    }
}
