<?php

namespace App\Models\Master;

use Illuminate\Database\Eloquent\Model;

class MasterVoucherDiskonJenis extends BaseMasterModel
{
    protected $table = 'master_voucher_diskon_jenis';

    protected $fillable = [
        'kode',
        'nama_jenis',
        'deskripsi',
        'bisa_treatment',
        'bisa_produk',
        'urutan',
        'is_active',
        'is_delete',
    ];

    protected $casts = [
        'bisa_treatment' => 'boolean',
        'bisa_produk' => 'boolean',
        'is_active' => 'boolean',
        'is_delete' => 'boolean',
    ];

    public function scopeActive($query)
    {
        return $query->where('is_active', 1)
            ->where('is_delete', 0);
    }

    public function scopeDeleted($query)
    {
        return $query->where('is_delete', 1);
    }

    public function markDeleted()
    {
        return $this->update([
            'is_delete' => 1,
            'is_active' => 0,
        ]);
    }

    public function restoreData()
    {
        return $this->update([
            'is_delete' => 0,
            'is_active' => 1,
        ]);
    }
}