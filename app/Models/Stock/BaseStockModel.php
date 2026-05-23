<?php

namespace App\Models\Stock;

use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Model;

class BaseStockModel extends Model
{
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

    public function markDeleted($user = null)
    {
        $this->is_delete = 1;

        if ($user !== null && $this->isFillableOrGuardedAllowed('updated_by')) {
            $this->updated_by = $user;
        }

        if ($this->isFillableOrGuardedAllowed('updated_at')) {
            $this->updated_at = now();
        }

        return $this->save();
    }

    public function restoreData($user = null)
    {
        $this->is_delete = 0;

        if ($user !== null && $this->isFillableOrGuardedAllowed('updated_by')) {
            $this->updated_by = $user;
        }

        if ($this->isFillableOrGuardedAllowed('updated_at')) {
            $this->updated_at = now();
        }

        return $this->save();
    }

    protected function isFillableOrGuardedAllowed($column)
    {
        return in_array($column, $this->getFillable())
            || empty($this->getFillable());
    }
}