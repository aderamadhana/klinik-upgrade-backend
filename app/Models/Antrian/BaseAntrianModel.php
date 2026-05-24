<?php

namespace App\Models\Antrian;

use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Model;

abstract class BaseAntrianModel extends Model
{
    use Auditable;

    protected $guarded = [];

    public $timestamps = true;

    protected $auditModuleName = 'Antrian';

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

    public function scopeByToko($query, $tokoId)
    {
        if (!$tokoId) {
            return $query;
        }

        return $query->where('toko_id', $tokoId);
    }

    public function markDeleted($userId = null)
    {
        $this->is_delete = 1;

        if ($userId && $this->isFillableOrGuarded('updated_by')) {
            $this->updated_by = $userId;
        }

        return $this->save();
    }

    public function restoreData($userId = null)
    {
        $this->is_delete = 0;

        if ($userId && $this->isFillableOrGuarded('updated_by')) {
            $this->updated_by = $userId;
        }

        return $this->save();
    }

    protected function isFillableOrGuarded($column)
    {
        return in_array($column, $this->getFillable(), true) || $this->getGuarded() === [];
    }
}