<?php

namespace App\Models\Master;

use Illuminate\Foundation\Auth\User as Authenticatable;
use PHPOpenSourceSaver\JWTAuth\Contracts\JWTSubject;

class MasterUser extends Authenticatable implements JWTSubject
{
    protected $table = 'master_user';

    protected $fillable = [
        'karyawan_id',
        'username',
        'password_hash',
        'email',
        'display_name',
        'is_active',
        'is_delete',
        'must_change_password',
    ];

    protected $hidden = [
        'password_hash',
    ];

    public function getAuthPassword()
    {
        return $this->password_hash;
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