<?php

namespace App\Models\Pembayaran;

use Illuminate\Database\Eloquent\Model;

class PembayaranInvoiceSequence extends BasePembayaranModel
{
    protected $table = 'pembayaran_invoice_sequence';

    protected $guarded = [];

    public $timestamps = true;

    protected $casts = [
        'toko_id' => 'integer',
        'tanggal' => 'date',
        'last_sequence' => 'integer',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];
}
