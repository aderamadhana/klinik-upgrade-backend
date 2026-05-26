<?php

namespace App\Models\Accurate;

use App\Models\Concerns\Auditable;
use App\Models\Pembayaran\PembayaranInvoice;
use Illuminate\Database\Eloquent\Model;

class AccurateSyncLog extends Model
{
    use Auditable;

    protected $table = 'accurate_sync_log';

    protected $guarded = [];

    public $timestamps = true;

    protected $auditModuleName = 'Accurate';

    protected $casts = [
        'pembayaran_id' => 'integer',
        'status' => 'integer',
        'retry_count' => 'integer',
        'synced_at' => 'datetime',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    public function pembayaran()
    {
        return $this->belongsTo(PembayaranInvoice::class, 'pembayaran_id');
    }

    public function scopePending($query)
    {
        return $query->where('status', 0);
    }

    public function scopeSuccess($query)
    {
        return $query->where('status', 1);
    }

    public function scopeFailed($query)
    {
        return $query->where('status', 2);
    }

    public function markSuccess($responsePayload = null): bool
    {
        return $this->update([
            'status' => 1,
            'response_payload' => $responsePayload,
            'error_message' => null,
            'synced_at' => now(),
        ]);
    }

    public function markFailed($errorMessage = null, $responsePayload = null): bool
    {
        return $this->update([
            'status' => 2,
            'response_payload' => $responsePayload,
            'error_message' => $errorMessage,
            'retry_count' => ((int) $this->retry_count) + 1,
        ]);
    }
}
