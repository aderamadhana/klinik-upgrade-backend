<?php

namespace App\Models\Poin;

use App\Models\Master\MasterPoinRule;
use App\Models\Master\MasterToko;
use App\Models\Master\MasterUser;
use App\Models\Concerns\Auditable;
use App\Models\Pasien;
use Illuminate\Database\Eloquent\Model;

class PasienPoinLedger extends Model
{
    use Auditable;
    protected $table = 'pasien_poin_ledger';

    protected $guarded = [];

    public $timestamps = false;

    protected $casts = [
        'pasien_id' => 'integer',
        'toko_id' => 'integer',
        'source_id' => 'integer',
        'tanggal' => 'datetime',
        'poin_masuk' => 'integer',
        'poin_keluar' => 'integer',
        'saldo_sebelum' => 'integer',
        'saldo_setelah' => 'integer',
        'nominal_transaksi' => 'decimal:2',
        'rule_id' => 'integer',
        'is_void' => 'integer',
        'void_reference_id' => 'integer',
        'created_by' => 'integer',
        'created_at' => 'datetime',
    ];

    public function pasien()
    {
        return $this->belongsTo(Pasien::class, 'pasien_id');
    }

    public function toko()
    {
        return $this->belongsTo(MasterToko::class, 'toko_id');
    }

    public function rule()
    {
        return $this->belongsTo(MasterPoinRule::class, 'rule_id');
    }

    public function voidReference()
    {
        return $this->belongsTo(self::class, 'void_reference_id');
    }

    public function voidChildren()
    {
        return $this->hasMany(self::class, 'void_reference_id');
    }

    public function createdBy()
    {
        return $this->belongsTo(MasterUser::class, 'created_by');
    }

    public function scopeNotVoid($query)
    {
        return $query->where('is_void', 0);
    }

    public function scopeByPasien($query, $pasienId)
    {
        return $query->where('pasien_id', $pasienId);
    }
}
