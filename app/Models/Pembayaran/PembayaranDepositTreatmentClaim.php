<?php

namespace App\Models\Pembayaran;

use App\Models\Registrasi\RegistrasiKunjungan;
use Illuminate\Database\Eloquent\Model;

class PembayaranDepositTreatmentClaim extends Model
{
    protected $table = 'pembayaran_deposit_treatment_claim';

    protected $guarded = [];

    public const STATUS_AKTIF = 1;
    public const STATUS_BATAL = 9;

    protected $casts = [
        'qty_claim' => 'decimal:4',
        'nilai_realisasi' => 'decimal:2',
        'claimed_at' => 'datetime',
        'status' => 'integer',
        'is_delete' => 'integer',
    ];

    public function scopeActive($query)
    {
        return $query
            ->where($this->getTable() . '.is_delete', 0)
            ->where($this->getTable() . '.status', self::STATUS_AKTIF);
    }

    public function depositTreatment()
    {
        return $this->belongsTo(PembayaranDepositTreatment::class, 'deposit_treatment_id');
    }

    public function invoice()
    {
        return $this->belongsTo(PembayaranInvoice::class, 'pembayaran_id');
    }

    public function item()
    {
        return $this->belongsTo(PembayaranInvoiceItem::class, 'pembayaran_item_id');
    }

    public function registrasi()
    {
        return $this->belongsTo(RegistrasiKunjungan::class, 'registrasi_id');
    }
}