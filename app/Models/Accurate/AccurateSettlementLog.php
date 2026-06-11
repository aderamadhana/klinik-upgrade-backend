<?php

namespace App\Models\Accurate;

use App\Models\Audit\Auditable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class AccurateSettlementLog extends Model
{
    use Auditable;

    public const STATUS_NO_DATA = 0;
    public const STATUS_SUCCESS = 1;
    public const STATUS_FAILED = 2;
    public const STATUS_PROCESSING = 3;

    protected $table = 'accurate_settlement_log';

    protected $guarded = [];

    protected $casts = [
        'tanggal_faktur' => 'date',
        'jenis_transaksi' => 'integer',
        'total_system' => 'decimal:2',
        'total_amount' => 'decimal:2',
        'status' => 'integer',
        'uploaded_at' => 'datetime',
    ];

    public function toko(): BelongsTo
    {
        return $this->belongsTo(\App\Models\master\MasterToko::class, 'toko_id');
    }

    public function scopeUmum($query)
    {
        return $query->where('jenis_transaksi', 0);
    }

    public function scopeBetweenTanggal($query, string $startDate, string $endDate)
    {
        return $query->whereBetween('tanggal_faktur', [$startDate, $endDate]);
    }

    public function isSuccess(): bool
    {
        return (int) $this->status === self::STATUS_SUCCESS;
    }

    public function markProcessing(array $payload, float $totalSystem, float $totalAmount, ?string $userName = null): void
    {
        $this->forceFill([
            'total_system' => $totalSystem,
            'total_amount' => $totalAmount,
            'status' => self::STATUS_PROCESSING,
            'request_payload' => json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'response_payload' => null,
            'error_message' => null,
            'uploaded_by' => $userName,
            'uploaded_at' => null,
        ])->save();
    }

    public function markSuccess(?string $fakturAccurate, array $responsePayload, ?string $userName = null): void
    {
        $this->forceFill([
            'faktur_accurate' => $fakturAccurate ?: $this->faktur_accurate,
            'status' => self::STATUS_SUCCESS,
            'response_payload' => json_encode($responsePayload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'error_message' => null,
            'uploaded_by' => $userName ?: $this->uploaded_by,
            'uploaded_at' => Carbon::now(),
        ])->save();
    }

    public function markFailed(string $message, array $responsePayload = []): void
    {
        $this->forceFill([
            'status' => self::STATUS_FAILED,
            'response_payload' => $responsePayload
                ? json_encode($responsePayload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
                : $this->response_payload,
            'error_message' => $message,
        ])->save();
    }

    public static function lockedDailyLog(int $tokoId, string $tanggalFaktur, int $jenisTransaksi = 0): ?self
    {
        return self::query()
            ->where('toko_id', $tokoId)
            ->whereDate('tanggal_faktur', $tanggalFaktur)
            ->where('jenis_transaksi', $jenisTransaksi)
            ->lockForUpdate()
            ->first();
    }

    public static function findOrCreateDailyLog(
        int $tokoId,
        string $tanggalFaktur,
        int $jenisTransaksi,
        string $deskripsiData,
        ?string $userName = null
    ): self {
        $log = self::lockedDailyLog($tokoId, $tanggalFaktur, $jenisTransaksi);

        if ($log) {
            return $log;
        }

        self::query()->create([
            'toko_id' => $tokoId,
            'tanggal_faktur' => $tanggalFaktur,
            'jenis_transaksi' => $jenisTransaksi,
            'deskripsi_data' => $deskripsiData,
            'status' => self::STATUS_NO_DATA,
            'created_by' => $userName,
        ]);

        return self::lockedDailyLog($tokoId, $tanggalFaktur, $jenisTransaksi);
    }
}
