<?php

namespace App\Models\Pembayaran;

use Illuminate\Database\Eloquent\Model;

class PembayaranInvoiceMetode extends Model
{
    protected $table = 'pembayaran_invoice_metode';

    protected $guarded = [];

    public const STATUS_AKTIF = 1;
    public const STATUS_BATAL = 9;

    public const TIPE_CASH_BANK_EDC_QRIS = 1;
    public const TIPE_DEPOSIT = 2;
    public const TIPE_POINT_MEMBER = 3;

    protected $casts = [
        'metode_bayar_tipe' => 'integer',
        'nominal_dialokasikan' => 'decimal:2',
        'nominal_diterima' => 'decimal:2',
        'nominal_kembalian' => 'decimal:2',
        'sort_order' => 'integer',
        'status' => 'integer',
        'is_delete' => 'integer',
    ];

    public function scopeActive($query)
    {
        return $query
            ->where($this->getTable() . '.is_delete', 0)
            ->where($this->getTable() . '.status', self::STATUS_AKTIF);
    }

    public function invoice()
    {
        return $this->belongsTo(PembayaranInvoice::class, 'pembayaran_id');
    }
}