<?php

namespace App\Models\Registrasi;

class RegistrasiDokterSoapSubjective extends BaseRegistrasiModel
{
    protected $table = 'registrasi_dokter_soap_subjective';

    public const UPDATED_AT = null;

    protected $hasDeleteFlag = false;

    protected $casts = [
        'soap_id' => 'integer',
        'subjective_id' => 'integer',
        'sort_order' => 'integer',
        'created_at' => 'datetime',
    ];

    public function soap()
    {
        return $this->belongsTo(RegistrasiDokterSoap::class, 'soap_id');
    }

    public function scopeBySoap($query, $soapId)
    {
        return $query->where('soap_id', $soapId);
    }
}
