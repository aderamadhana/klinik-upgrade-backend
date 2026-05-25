<?php

namespace App\Models\Master;

use Illuminate\Database\Eloquent\Model;

class MasterPoinRule extends Model
{
    protected $table = 'master_poin_rules';

    protected $primaryKey = 'id';

    public $timestamps = true;

    protected $guarded = [];

    protected $casts = [
        'nominal_per_poin' => 'decimal:2',
        'minimal_transaksi' => 'decimal:2',
        'berlaku_mulai' => 'date',
        'berlaku_sampai' => 'date',
        'is_berlaku_kelipatan' => 'integer',
        'is_active' => 'integer',
        'is_delete' => 'integer',
        'created_by' => 'integer',
        'updated_by' => 'integer',
    ];

    public function scopeActive($query)
    {
        return $query->where('is_delete', 0);
    }

    public function scopeAktif($query)
    {
        return $query->where('is_active', 1)->where('is_delete', 0);
    }
}