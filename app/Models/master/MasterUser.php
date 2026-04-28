<?php

namespace App\Models\Master;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use PHPOpenSourceSaver\JWTAuth\Contracts\JWTSubject;

class MasterUser extends Authenticatable implements JWTSubject
{
    use HasFactory;

    protected $table = 'master_user';
    protected $primaryKey = 'id';

    protected $guarded = [];

    public $timestamps = false;

    protected $hidden = [
        'password',
        'remember_token',
    ];

    public function role()
    {
        return $this->belongsTo(MasterRole::class, 'role_id', 'id');
    }

    public function karyawan()
    {
        return $this->belongsTo(MasterKaryawan::class, 'karyawan_id', 'id');
    }

    public function penempatan()
    {
        return $this->hasMany(MasterUserPenempatan::class, 'user_id')
            ->where('is_delete', 0);
    }

    public function toko()
    {
        return $this->belongsTo(MasterToko::class, 'toko_id', 'id');
    }

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

    public function getJWTIdentifier()
    {
        return $this->getKey();
    }

    public function getJWTCustomClaims()
    {
        return [];
    }
}