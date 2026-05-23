<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AuditLog extends Model
{
    protected $table = 'audit_logs';

    protected $primaryKey = 'id';

    protected $guarded = [];

    public $timestamps = false;

    protected $casts = [
        'id' => 'integer',
        'record_id' => 'integer',
        'toko_id' => 'integer',
        'user_id' => 'integer',
        'created_at' => 'datetime',
    ];
}