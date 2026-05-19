<?php

namespace App\Models\Master;

use Illuminate\Database\Eloquent\Model;

class MasterAgama extends Model
{
    protected $table = 'master_agama';

    protected $guarded = [];

    public $timestamps = false;

    public function scopeActive($query)
    {
        return $query->where(function ($q) {
                $q->where('is_delete', 0)
                  ->orWhereNull('is_delete');
            })
            ->where('is_active', 1);
    }
}