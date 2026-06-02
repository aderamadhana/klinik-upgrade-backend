<?php

namespace App\Models\Pembayaran;

use Illuminate\Database\Eloquent\Model;

class PembayaranDepositTreatment extends Model
{
    protected $table = 'pembayaran_deposit_treatment';

    protected $guarded = [];

    public $timestamps = true;

    public const STATUS_AKTIF = 1;
    public const STATUS_HABIS = 2;
    public const STATUS_EXPIRED = 3;
    public const STATUS_BATAL = 9;

    public function scopeActive($query)
    {
        return $query->where(function ($q) {
            $q->whereNull('is_delete')->orWhere('is_delete', 0);
        });
    }

    public function scopeAvailable($query)
    {
        return $query->active()
            ->where('status', self::STATUS_AKTIF)
            ->where('qty_sisa', '>', 0);
    }

    public function invoice()
    {
        return $this->belongsTo(PembayaranInvoice::class, 'pembayaran_id');
    }

    public function claims()
    {
        return $this->hasMany(PembayaranDepositTreatmentClaim::class, 'deposit_treatment_id');
    }
}
