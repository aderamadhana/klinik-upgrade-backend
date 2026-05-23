<?php

namespace App\Models\Pembayaran;

use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class BasePembayaranModel extends Model
{
    use HasFactory;
    use Auditable;

    protected $guarded = [];

    public $timestamps = false;

    public function scopeActive($query)
    {
        return $query->where(function ($q) {
            $q->where('is_delete', 0)
              ->orWhereNull('is_delete');
        });
    }

    public function scopeDeleted($query)
    {
        return $query->where('is_delete', 1);
    }

    public function markDeleted()
    {
        $this->is_delete = 1;
        return $this->save();
    }

    public function restoreData()
    {
        $this->is_delete = 0;
        return $this->save();
    }
}