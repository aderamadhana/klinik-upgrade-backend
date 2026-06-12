<?php

namespace App\Models\Accurate;

use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Model;

class AccurateDepositRealizationSettlementLog extends Model
{
    use Auditable;

    public const STATUS_PENDING = 0;
    public const STATUS_PROCESSING = 1;
    public const STATUS_SUCCESS = 2;
    public const STATUS_FAILED = 3;

    protected $table = 'accurate_deposit_realization_settlement_log';

    protected $guarded = [];

    public $timestamps = true;

    protected $auditModuleName = 'Accurate';

    protected $casts = [
        'deposit_claim_id' => 'integer',
        'deposit_treatment_id' => 'integer',
        'pembayaran_deposit_id' => 'integer',
        'pembayaran_realisasi_id' => 'integer',
        'pasien_id' => 'integer',
        'toko_id' => 'integer',
        'tanggal_faktur' => 'date',
        'total_realisasi' => 'decimal:2',
        'status' => 'integer',
        'request_payload' => 'array',
        'response_payload' => 'array',
        'retry_count' => 'integer',
        'uploaded_at' => 'datetime',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    public function isSuccess(): bool
    {
        return (int) $this->status === self::STATUS_SUCCESS;
    }

    public function markProcessing(
        array $payload,
        float $totalRealisasi,
        ?string $userName = null
    ): bool {
        return $this->forceFill([
            'request_payload' => $payload,
            'response_payload' => null,
            'error_message' => null,
            'total_realisasi' => round($totalRealisasi, 2),
            'status' => self::STATUS_PROCESSING,
            'uploaded_by' => $userName ?: $this->uploaded_by,
            'updated_by' => $userName ?: $this->updated_by,
        ])->save();
    }

    public function markSuccess(
        ?string $fakturAccurate,
        mixed $responsePayload,
        ?string $message = null,
        ?string $userName = null
    ): bool {
        return $this->forceFill([
            'faktur_accurate' => $fakturAccurate,
            'response_payload' => $responsePayload,
            'keterangan' => $message,
            'error_message' => null,
            'status' => self::STATUS_SUCCESS,
            'uploaded_by' => $userName ?: $this->uploaded_by,
            'uploaded_at' => now(),
            'updated_by' => $userName ?: $this->updated_by,
        ])->save();
    }

    public function markFailed(?string $errorMessage = null): bool
    {
        return $this->forceFill([
            'status' => self::STATUS_FAILED,
            'error_message' => $errorMessage,
            'retry_count' => ((int) $this->retry_count) + 1,
        ])->save();
    }
}