<?php

namespace App\Models\Pembayaran;

use App\Models\Pasien;
use App\Models\Registrasi\RegistrasiKunjungan;
use App\Models\Registrasi\RegistrasiTask;
use Illuminate\Database\Eloquent\Model;

class PembayaranInvoice extends Model
{
    protected $table = 'pembayaran_invoice';

    protected $guarded = [];

    public const STATUS_DRAFT = 0;
    public const STATUS_MENUNGGU = 1;
    public const STATUS_PROSES = 2;
    public const STATUS_LUNAS = 3;
    public const STATUS_BATAL = 9;

    protected $casts = [
        'tanggal_invoice' => 'datetime',
        'tanggal_lunas' => 'datetime',
        'deposit_expired_at' => 'date',

        'subtotal_produk' => 'decimal:2',
        'subtotal_treatment' => 'decimal:2',
        'subtotal_konsultasi' => 'decimal:2',
        'subtotal' => 'decimal:2',
        'diskon_subtotal_nilai' => 'decimal:2',
        'diskon_subtotal_amount' => 'decimal:2',
        'total_diskon_item' => 'decimal:2',
        'total_diskon_referral' => 'decimal:2',
        'total_promo' => 'decimal:2',
        'diskon_member_amount' => 'decimal:2',
        'point_earned' => 'decimal:2',
        'point_redeemed' => 'decimal:2',
        'point_redeem_value' => 'decimal:2',
        'grand_total' => 'decimal:2',
        'total_bayar' => 'decimal:2',

        'status' => 'integer',
        'is_delete' => 'integer',
    ];

    public function scopeActive($query)
    {
        return $query->where($this->getTable() . '.is_delete', 0);
    }

    public function registrasi()
    {
        return $this->belongsTo(RegistrasiKunjungan::class, 'registrasi_id');
    }

    public function task()
    {
        return $this->belongsTo(RegistrasiTask::class, 'task_id');
    }

    public function pasien()
    {
        return $this->belongsTo(Pasien::class, 'pasien_id');
    }

    public function items()
    {
        return $this->hasMany(PembayaranInvoiceItem::class, 'pembayaran_id');
    }

    public function metode()
    {
        return $this->hasMany(PembayaranInvoiceMetode::class, 'pembayaran_id');
    }

    public function promos()
    {
        return $this->hasMany(PembayaranInvoicePromo::class, 'pembayaran_id');
    }

    public function depositClaims()
    {
        return $this->hasMany(PembayaranDepositTreatmentClaim::class, 'pembayaran_id');
    }
}