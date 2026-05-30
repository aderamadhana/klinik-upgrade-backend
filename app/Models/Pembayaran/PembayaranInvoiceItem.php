<?php

namespace App\Models\Pembayaran;

use App\Models\Registrasi\RegistrasiKunjungan;
use Illuminate\Database\Eloquent\Model;

class PembayaranInvoiceItem extends BasePembayaranModel
{
    protected $table = 'pembayaran_invoice_item';

    protected $guarded = [];

    public const ITEM_KONSULTASI = 1;
    public const ITEM_TREATMENT = 2;
    public const ITEM_PRODUK = 3;
    public const ITEM_DEPOSIT = 4;

    public const SOURCE_MANUAL = 0;
    public const SOURCE_REGISTRASI_TREATMENT = 1;
    public const SOURCE_REGISTRASI_PENJUALAN = 2;
    public const SOURCE_RESEP_DOKTER = 3;
    public const SOURCE_REGISTRASI_KONSULTASI = 4;

    public const STATUS_AKTIF = 1;
    public const STATUS_BATAL = 9;

    protected $casts = [
        'qty' => 'decimal:4',
        'harga' => 'decimal:2',
        'diskon_nilai' => 'decimal:2',
        'diskon_amount' => 'decimal:2',
        'diskon_referral' => 'decimal:2',
        'subtotal' => 'decimal:2',
        'expired_at' => 'date',

        'item_type' => 'integer',
        'source_type' => 'integer',
        'diskon_tipe' => 'integer',
        'status' => 'integer',
        'is_delete' => 'integer',
        'is_saran_dokter' => 'integer',
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

    public function registrasi()
    {
        return $this->belongsTo(RegistrasiKunjungan::class, 'registrasi_id');
    }

    public function promos()
    {
        return $this->hasMany(PembayaranInvoicePromo::class, 'pembayaran_item_id');
    }

    public function depositClaims()
    {
        return $this->hasMany(PembayaranDepositTreatmentClaim::class, 'pembayaran_item_id');
    }
}