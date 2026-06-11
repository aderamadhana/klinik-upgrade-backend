<?php

namespace App\Models\Master;

use Illuminate\Database\Eloquent\Model;

class MasterMerchandise extends Model
{
    protected $table = 'master_merchandise';

    protected $guarded = [];

    public $timestamps = true;

    protected $casts = [
        'nilai_diskon_persen' => 'decimal:2',
        'nilai_diskon_nominal' => 'decimal:2',
        'harga_poin' => 'integer',
        'stok' => 'integer',
        'is_delete' => 'integer',
        'sort_order' => 'integer',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    public function redeemDetails()
    {
        return $this->hasMany(PasienPoinRedeemDetail::class, 'merchandise_id');
    }

    public function scopeActive($query)
    {
        return $query->where(function ($q) {
            $q->where('is_delete', 0)->orWhereNull('is_delete');
        });
    }

    public function scopeSearch($query, ?string $search)
    {
        $search = trim((string) $search);

        if ($search === '') {
            return $query;
        }

        return $query->where(function ($q) use ($search) {
            $q->where('kode', 'like', "%{$search}%")
                ->orWhere('nama', 'like', "%{$search}%")
                ->orWhere('jenis_reward', 'like', "%{$search}%");
        });
    }

    public function getLabelAttribute(): string
    {
        return trim(($this->kode ? "{$this->kode} - " : '') . $this->nama);
    }
}