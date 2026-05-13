<?php

namespace App\Models\Master;

use Illuminate\Database\Eloquent\Model;

class MasterVoucherDiskonTemplate extends Model
{
    protected $table = 'master_voucher_diskon_template';

    protected $fillable = [
        'kode',
        'nama_template',
        'deskripsi',
        'file_url',
        'file_name',
        'urutan',
        'is_active',
        'is_delete',
    ];

    protected $casts = [
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