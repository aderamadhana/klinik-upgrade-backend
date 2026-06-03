<?php

namespace App\Models\Poin;

use App\Models\Pasien;
use App\Models\PasienMember;
use App\Models\Concerns\Auditable;
use App\Models\Pembayaran\PembayaranInvoice;
use App\Models\Registrasi\RegistrasiKunjungan;
use Illuminate\Database\Eloquent\Model;

class MemberPointLedger extends Model
{
    use Auditable;
    public const TYPE_EARN = 1;
    public const TYPE_REDEEM = 2;
    public const TYPE_ADJUSTMENT = 3;
    protected $table = 'member_point_ledger';

    protected $guarded = [];

    public $timestamps = true;

    protected $casts = [
        'member_id' => 'integer',
        'pasien_id' => 'integer',
        'pembayaran_id' => 'integer',
        'registrasi_id' => 'integer',
        'transaksi_type' => 'integer',
        'point_masuk' => 'decimal:2',
        'point_keluar' => 'decimal:2',
        'nominal_referensi' => 'decimal:2',
        'tanggal_transaksi' => 'datetime',
        'is_void' => 'integer',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    public function member()
    {
        return $this->belongsTo(PasienMember::class, 'member_id');
    }

    public function pasien()
    {
        return $this->belongsTo(Pasien::class, 'pasien_id');
    }

    public function pembayaran()
    {
        return $this->belongsTo(PembayaranInvoice::class, 'pembayaran_id');
    }

    public function registrasi()
    {
        return $this->belongsTo(RegistrasiKunjungan::class, 'registrasi_id');
    }

    public function scopeNotVoid($query)
    {
        return $query->where('is_void', 0);
    }

    public function scopeEarn($query)
    {
        return $query->where('transaksi_type', 1);
    }

    public function scopeRedeem($query)
    {
        return $query->where('transaksi_type', 2);
    }
}
