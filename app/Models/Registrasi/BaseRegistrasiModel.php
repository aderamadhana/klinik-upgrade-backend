<?php

namespace App\Models\Registrasi;

use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Model;

abstract class BaseRegistrasiModel extends Model
{
    use Auditable;

    protected $guarded = [];

    public $timestamps = true;

    protected $hasDeleteFlag = true;

    public function scopeActive($query)
    {
        if (!$this->hasDeleteFlag) {
            return $query;
        }

        return $query->where(function ($q) {
            $q->where('is_delete', 0)
                ->orWhereNull('is_delete');
        });
    }

    public function scopeDeleted($query)
    {
        if (!$this->hasDeleteFlag) {
            return $query;
        }

        return $query->where('is_delete', 1);
    }

    public function scopeByRegistrasi($query, $registrasiId)
    {
        return $query->where('registrasi_id', $registrasiId);
    }

    public function scopeByStatus($query, $status)
    {
        return $query->where('status', $status);
    }

    public function markDeleted($updatedBy = null)
    {
        if (!$this->hasDeleteFlag) {
            return false;
        }

        $this->is_delete = 1;

        if ($updatedBy !== null) {
            $this->updated_by = $updatedBy;
        }

        return $this->save();
    }

    public function restoreData($updatedBy = null)
    {
        if (!$this->hasDeleteFlag) {
            return false;
        }

        $this->is_delete = 0;

        if ($updatedBy !== null) {
            $this->updated_by = $updatedBy;
        }

        return $this->save();
    }
}