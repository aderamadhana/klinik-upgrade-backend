<?php

namespace App\Models\Poin;

use App\Models\Master\MasterMerchandise;
use Illuminate\Database\Eloquent\Model;

class PasienPoinRedeemDetail extends Model
{
    protected $table = 'pasien_poin_redeem_detail';

    protected $guarded = [];

    public $timestamps = true;

    protected $casts = [
        'redeem_id' => 'integer',
        'merchandise_id' => 'integer',
        'harga_poin_snapshot' => 'integer',
        'nilai_diskon_persen_snapshot' => 'decimal:2',
        'nilai_diskon_nominal_snapshot' => 'decimal:2',
        'qty' => 'integer',
        'subtotal_poin' => 'integer',
        'subtotal_diskon_nominal' => 'decimal:2',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    public function redeem()
    {
        return $this->belongsTo(PasienPoinRedeem::class, 'redeem_id');
    }

    public function merchandise()
    {
        return $this->belongsTo(MasterMerchandise::class, 'merchandise_id');
    }
}