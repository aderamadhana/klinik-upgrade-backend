<?php

namespace App\Models\Pembayaran;

use App\Models\Pasien;
use Illuminate\Database\Eloquent\Model;

class PembayaranDepositTreatment extends Model
{
    protected $table = 'pembayaran_deposit_treatment';

    protected $guarded = [];

    public const STATUS_AKTIF = 1;
    public const STATUS_HABIS = 2;
    public const STATUS_EXPIRED = 3;
    public const STATUS_BATAL = 9;

    public const CLAIM_SCOPE_CABANG = 1;
    public const CLAIM_SCOPE_GLOBAL = 2;

    protected $casts = [
        'qty_total' => 'decimal:4',
        'qty_claimed' => 'decimal:4',
        'qty_sisa' => 'decimal:4',
        'harga_satuan' => 'decimal:2',
        'total_nilai' => 'decimal:2',
        'nilai_claimed' => 'decimal:2',
        'nilai_sisa' => 'decimal:2',
        'expired_at' => 'date',

        'claim_scope' => 'integer',
        'status' => 'integer',
        'is_delete' => 'integer',
    ];

    public function scopeActive($query)
    {
        return $query
            ->where($this->getTable() . '.is_delete', 0)
            ->where($this->getTable() . '.status', self::STATUS_AKTIF);
    }

    public function pasien()
    {
        return $this->belongsTo(Pasien::class, 'pasien_id');
    }

    public function claims()
    {
        return $this->hasMany(PembayaranDepositTreatmentClaim::class, 'deposit_treatment_id');
    }
}