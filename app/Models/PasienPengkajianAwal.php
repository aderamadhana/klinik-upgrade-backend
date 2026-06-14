<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Models\Master\MasterKaryawan;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PasienPengkajianAwal extends Model
{
    use Auditable;

    protected $table = 'pasien_pengkajian_awal';

    protected $guarded = ['id'];

    public $timestamps = true;

    protected $casts = [
        'id' => 'integer',
        'pasien_id' => 'integer',
        'pemeriksa_id' => 'integer',
        'tanggal_pengkajian' => 'datetime',
        'o_eye_gcs' => 'integer',
        'o_verbal_gcs' => 'integer',
        'o_motor_gcs' => 'integer',
        'o_keadaan_tht_checklist' => 'integer',
        'o_keadaan_kepala_checklist' => 'integer',
        'o_keadaan_mata_checklist' => 'integer',
        'o_keadaan_leher_checklist' => 'integer',
        'o_keadaan_paru_checklist' => 'integer',
        'o_keadaan_jantung_checklist' => 'integer',
        'o_keadaan_abdomen_checklist' => 'integer',
        'o_keadaan_ekstremitas_checklist' => 'integer',
        'o_keadaan_kulit_checklist' => 'integer',
        'info_hasil_pemeriksaan' => 'boolean',
        'info_tindakan_pengobatan_resiko' => 'boolean',
        'info_kemungkinan_komplikasi' => 'boolean',
        'status_paham_pasien' => 'integer',
        'tanggal_kontrol' => 'date',
        'is_delete' => 'integer',
        'created_by' => 'integer',
        'updated_by' => 'integer',
    ];

    public function scopeActive(Builder $query): Builder
    {
        return $query->where(function (Builder $builder) {
            $builder->where('is_delete', 0)
                ->orWhereNull('is_delete');
        });
    }

    public function pasien(): BelongsTo
    {
        return $this->belongsTo(Pasien::class, 'pasien_id', 'id');
    }

    public function pemeriksa(): BelongsTo
    {
        return $this->belongsTo(MasterKaryawan::class, 'pemeriksa_id', 'id');
    }
}
