<?php

namespace App\Models\Pembayaran;

use Illuminate\Database\Eloquent\Model;

class PembayaranInvoicePromo extends BasePembayaranModel
{
    protected $table = 'pembayaran_invoice_promo';

    protected $guarded = [];

    public const SCOPE_INVOICE = 1;
    public const SCOPE_ITEM = 2;

    public const DISKON_PERSEN = 1;
    public const DISKON_RUPIAH = 2;

    protected $casts = [
        'scope_type' => 'integer',
        'diskon_tipe' => 'integer',
        'diskon_nilai' => 'decimal:2',
        'diskon_amount' => 'decimal:2',
        'is_delete' => 'integer',
    ];

    public function scopeActive($query)
    {
        return $query->where($this->getTable() . '.is_delete', 0);
    }

    public function invoice()
    {
        return $this->belongsTo(PembayaranInvoice::class, 'pembayaran_id');
    }

    public function item()
    {
        return $this->belongsTo(PembayaranInvoiceItem::class, 'pembayaran_item_id');
    }
}