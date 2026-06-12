<?php

namespace App\Models\Accurate;

use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

class AccurateSettlementLog extends Model
{
    use Auditable;

    public const STATUS_NO_DATA = 0;
    public const STATUS_PROCESSING = 1;
    public const STATUS_SUCCESS = 2;
    public const STATUS_FAILED = 3;

    protected $table = 'accurate_settlement_log';

    protected $guarded = [];

    public $timestamps = true;

    protected $auditModuleName = 'Accurate';

    protected $casts = [
        'toko_id' => 'integer',
        'jenis_transaksi' => 'integer',
        'status' => 'integer',
        'total_system' => 'decimal:2',
        'total_amount' => 'decimal:2',
        'retry_count' => 'integer',
        'tanggal_faktur' => 'date',
        'request_payload' => 'array',
        'response_payload' => 'array',
        'uploaded_at' => 'datetime',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    public function scopeUmum(Builder $query): Builder
    {
        return $query->where('jenis_transaksi', 0);
    }

    public function scopeJenisTransaksi(
        Builder $query,
        int $jenisTransaksi
    ): Builder {
        return $query->where('jenis_transaksi', $jenisTransaksi);
    }

    public function scopeBetweenTanggal(
        Builder $query,
        string $startDate,
        string $endDate
    ): Builder {
        return $query->whereBetween('tanggal_faktur', [$startDate, $endDate]);
    }

    public static function findOrCreateDailyLog(
        int $tokoId,
        string $tanggalFaktur,
        int $jenisTransaksi,
        ?string $deskripsiData = null,
        ?string $userName = null
    ): self {
        $tanggalFaktur = Carbon::parse($tanggalFaktur)->toDateString();

        return self::firstOrCreate(
            [
                'toko_id' => $tokoId,
                'tanggal_faktur' => $tanggalFaktur,
                'jenis_transaksi' => $jenisTransaksi,
            ],
            [
                'deskripsi_data' => $deskripsiData,
                'total_system' => 0,
                'total_amount' => 0,
                'status' => self::STATUS_NO_DATA,
                'uploaded_by' => $userName,
                'created_by' => $userName,
                'updated_by' => $userName,
            ]
        );
    }

    public function isSuccess(): bool
    {
        return (int) $this->status === self::STATUS_SUCCESS;
    }

    public function markProcessing(
        array $requestPayload,
        float $totalSystem,
        float $totalAmount,
        ?string $userName = null
    ): bool {
        return $this->forceFill([
            'request_payload' => $requestPayload,
            'response_payload' => null,
            'error_message' => null,
            'total_system' => round($totalSystem, 2),
            'total_amount' => round($totalAmount, 2),
            'status' => self::STATUS_PROCESSING,
            'uploaded_by' => $userName ?: $this->uploaded_by,
            'updated_by' => $userName ?: $this->updated_by,
        ])->save();
    }

    public function markSuccess(
        ?string $fakturAccurate = null,
        mixed $responsePayload = null,
        ?string $userName = null
    ): bool {
        return $this->forceFill([
            'faktur_accurate' => $fakturAccurate,
            'response_payload' => $responsePayload,
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