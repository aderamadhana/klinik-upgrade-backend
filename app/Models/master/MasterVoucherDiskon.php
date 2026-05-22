<?php

namespace App\Models\Master;

class MasterVoucherDiskon extends BaseMasterModel
{
    protected $table = 'master_voucher_diskon';
    protected $primaryKey = 'id';

    public function toko()
    {
        return $this->belongsTo(MasterToko::class, 'toko_id', 'id');
    }

    public function items()
    {
        return $this->hasMany(MasterVoucherDiskonItem::class, 'voucher_diskon_id', 'id')
            ->active();
    }

    public function semuaItems()
    {
        return $this->hasMany(MasterVoucherDiskonItem::class, 'voucher_diskon_id', 'id');
    }

    public function scopeAktifVoucher($query)
    {
        return $query->where('status_voucher', 1);
    }

    public function scopeDraftVoucher($query)
    {
        return $query->where('status_voucher', 0);
    }

    public function scopeNonaktifVoucher($query)
    {
        return $query->where('status_voucher', 2);
    }

    public function getStatusVoucherLabelAttribute()
    {
        $map = [
            0 => 'Draft',
            1 => 'Aktif',
            2 => 'Nonaktif',
        ];

        return $map[(int) $this->status_voucher] ?? '-';
    }

    public function getModeVoucherLabelAttribute()
    {
        $map = [
            'direct' => 'Direct Code',
            'generate' => 'Generate Banyak Kode',
        ];

        return $map[$this->mode_voucher] ?? '-';
    }

    public function getTipeDiskonLabelAttribute()
    {
        $map = [
            'percent' => 'Persen',
            'nominal' => 'Nominal',
        ];

        return $map[$this->tipe_diskon] ?? '-';
    }

    public function scopeAktifEligible($query)
    {
        return $query
            ->where('status_voucher', 1)
            ->where(function ($q) {
                $q->where('is_delete', 0)
                    ->orWhereNull('is_delete');
            });
    }

    public function scopeUntukToko($query, $tokoId = null)
    {
        return $query->where(function ($q) use ($tokoId) {
            $q->where('is_all_toko', 1);

            if (!empty($tokoId)) {
                $q->orWhere('toko_id', $tokoId);
            }
        });
    }

    public function scopePeriodeMasihBerlaku($query)
    {
        $today = now()->toDateString();

        return $query->where(function ($q) use ($today) {
            $q->where('is_unlimited_date', 1)
                ->orWhere(function ($dateQuery) use ($today) {
                    $dateQuery
                        ->whereDate('tanggal_mulai', '<=', $today)
                        ->whereDate('tanggal_akhir', '>=', $today);
                });
        });
    }

    public function getJenisVoucherLabelAttribute()
    {
        $map = [
            1 => 'Treatment',
            2 => 'Produk',
            3 => 'Bundling',
            4 => 'Value',
        ];

        return $map[(int) $this->jenis_voucher_id] ?? '-';
    }

    public function getTipeDiskonKodeAttribute()
    {
        return $this->tipe_diskon === 'nominal' ? 'Rp' : '%';
    }
}