<?php

namespace App\Models\Antrian;

use App\Models\Master\MasterToko;
use App\Models\Pasien;
use App\Models\Registrasi\RegistrasiLayanan;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Antrian extends BaseAntrianModel
{
    protected $table = 'antrian';

    protected $primaryKey = 'id';

    public const SOURCE_WALK_IN = 'walk_in';
    public const SOURCE_BOOKING = 'booking';
    public const SOURCE_MANUAL = 'manual';

    public const STATUS_WAITING = 'waiting';
    public const STATUS_CALLED = 'called';
    public const STATUS_SERVING = 'serving';
    public const STATUS_SKIPPED = 'skipped';
    public const STATUS_FINISHED = 'finished';
    public const STATUS_CANCELLED = 'cancelled';

    protected $casts = [
        'tanggal' => 'date:Y-m-d',
        'toko_id' => 'integer',
        'kategori_id' => 'integer',
        'nomor' => 'integer',
        'source_id' => 'integer',
        'pasien_id' => 'integer',
        'registrasi_id' => 'integer',
        'appointment_at' => 'datetime',
        'checkin_at' => 'datetime',
        'priority_level' => 'integer',
        'counter_id' => 'integer',
        'called_at' => 'datetime',
        'served_at' => 'datetime',
        'skipped_at' => 'datetime',
        'finished_at' => 'datetime',
        'cancelled_at' => 'datetime',
        'called_by' => 'integer',
        'served_by' => 'integer',
        'finished_by' => 'integer',
        'cancelled_by' => 'integer',
        'is_delete' => 'boolean',
        'created_by' => 'integer',
        'updated_by' => 'integer',
    ];

    public function toko(): BelongsTo
    {
        return $this->belongsTo(MasterToko::class, 'toko_id', 'id');
    }

    public function kategori(): BelongsTo
    {
        return $this->belongsTo(MasterAntrianKategori::class, 'kategori_id', 'id');
    }

    public function counter(): BelongsTo
    {
        return $this->belongsTo(MasterAntrianCounter::class, 'counter_id', 'id');
    }

    public function pasien(): BelongsTo
    {
        return $this->belongsTo(Pasien::class, 'pasien_id', 'id');
    }

    public function registrasi(): BelongsTo
    {
        return $this->belongsTo(RegistrasiLayanan::class, 'registrasi_id', 'id');
    }

    public function booking(): BelongsTo
    {
        return $this->belongsTo(BookingLayanan::class, 'source_id', 'id');
    }

    public function logs(): HasMany
    {
        return $this->hasMany(AntrianLog::class, 'antrian_id', 'id');
    }

    public function scopeHariIni($query)
    {
        return $query->whereDate('tanggal', now()->toDateString());
    }

    public function scopeByTanggal($query, $tanggal)
    {
        if (!$tanggal) {
            return $query;
        }

        return $query->whereDate('tanggal', $tanggal);
    }

    public function scopeWaiting($query)
    {
        return $query->where('status', self::STATUS_WAITING);
    }

    public function scopeCalled($query)
    {
        return $query->where('status', self::STATUS_CALLED);
    }

    public function scopeServing($query)
    {
        return $query->where('status', self::STATUS_SERVING);
    }

    public function scopeSkipped($query)
    {
        return $query->where('status', self::STATUS_SKIPPED);
    }

    public function scopeFinished($query)
    {
        return $query->where('status', self::STATUS_FINISHED);
    }

    public function scopeCancelled($query)
    {
        return $query->where('status', self::STATUS_CANCELLED);
    }

    public function scopeAktifHariIni($query, $tokoId = null)
    {
        return $query->active()
            ->hariIni()
            ->when($tokoId, function ($q) use ($tokoId) {
                $q->where('toko_id', $tokoId);
            });
    }

    public function scopeDisplayAktif($query)
    {
        return $query->whereIn('status', [
            self::STATUS_CALLED,
            self::STATUS_SERVING,
        ]);
    }

    public function scopeBelumSelesai($query)
    {
        return $query->whereIn('status', [
            self::STATUS_WAITING,
            self::STATUS_CALLED,
            self::STATUS_SERVING,
            self::STATUS_SKIPPED,
        ]);
    }

    public function scopePrioritasPanggilan($query)
    {
        return $query->orderByDesc('priority_level')
            ->orderByRaw('CASE WHEN appointment_at IS NULL THEN 1 ELSE 0 END ASC')
            ->orderBy('appointment_at', 'ASC')
            ->orderBy('checkin_at', 'ASC')
            ->orderBy('created_at', 'ASC')
            ->orderBy('nomor', 'ASC');
    }

    public function isBooking()
    {
        return $this->source_type === self::SOURCE_BOOKING;
    }

    public function isWalkIn()
    {
        return $this->source_type === self::SOURCE_WALK_IN;
    }

    public function isManual()
    {
        return $this->source_type === self::SOURCE_MANUAL;
    }

    public function canCall()
    {
        return in_array($this->status, [
            self::STATUS_WAITING,
            self::STATUS_SKIPPED,
        ], true);
    }

    public function canServe()
    {
        return $this->status === self::STATUS_CALLED;
    }

    public function canFinish()
    {
        return $this->status === self::STATUS_SERVING;
    }

    public function markCalled($counterId, $userId = null)
    {
        $statusBefore = $this->status;

        $this->status = self::STATUS_CALLED;
        $this->counter_id = $counterId;
        $this->called_at = now();
        $this->called_by = $userId;

        $saved = $this->save();

        if ($saved) {
            $this->logs()->create([
                'toko_id' => $this->toko_id,
                'counter_id' => $counterId,
                'action' => AntrianLog::ACTION_CALL,
                'status_before' => $statusBefore,
                'status_after' => self::STATUS_CALLED,
                'action_by' => $userId,
                'action_at' => now(),
            ]);

            if ($this->isBooking() && $this->booking) {
                $this->booking->markCalled();
            }
        }

        return $saved;
    }

    public function markRecalled($counterId, $userId = null)
    {
        $this->logs()->create([
            'toko_id' => $this->toko_id,
            'counter_id' => $counterId,
            'action' => AntrianLog::ACTION_RECALL,
            'status_before' => $this->status,
            'status_after' => $this->status,
            'action_by' => $userId,
            'action_at' => now(),
        ]);

        return true;
    }

    public function markServing($userId = null)
    {
        $statusBefore = $this->status;

        $this->status = self::STATUS_SERVING;
        $this->served_at = now();
        $this->served_by = $userId;

        $saved = $this->save();

        if ($saved) {
            $this->logs()->create([
                'toko_id' => $this->toko_id,
                'counter_id' => $this->counter_id,
                'action' => AntrianLog::ACTION_SERVE,
                'status_before' => $statusBefore,
                'status_after' => self::STATUS_SERVING,
                'action_by' => $userId,
                'action_at' => now(),
            ]);

            if ($this->isBooking() && $this->booking) {
                $this->booking->markServing();
            }
        }

        return $saved;
    }

    public function markSkipped($userId = null)
    {
        $statusBefore = $this->status;

        $this->status = self::STATUS_SKIPPED;
        $this->skipped_at = now();

        $saved = $this->save();

        if ($saved) {
            $this->logs()->create([
                'toko_id' => $this->toko_id,
                'counter_id' => $this->counter_id,
                'action' => AntrianLog::ACTION_SKIP,
                'status_before' => $statusBefore,
                'status_after' => self::STATUS_SKIPPED,
                'action_by' => $userId,
                'action_at' => now(),
            ]);
        }

        return $saved;
    }

    public function markFinished($userId = null)
    {
        $statusBefore = $this->status;

        $this->status = self::STATUS_FINISHED;
        $this->finished_at = now();
        $this->finished_by = $userId;

        $saved = $this->save();

        if ($saved) {
            $this->logs()->create([
                'toko_id' => $this->toko_id,
                'counter_id' => $this->counter_id,
                'action' => AntrianLog::ACTION_FINISH,
                'status_before' => $statusBefore,
                'status_after' => self::STATUS_FINISHED,
                'action_by' => $userId,
                'action_at' => now(),
            ]);

            if ($this->isBooking() && $this->booking) {
                $this->booking->markCompleted();
            }
        }

        return $saved;
    }

    public function markCancelled($userId = null)
    {
        $statusBefore = $this->status;

        $this->status = self::STATUS_CANCELLED;
        $this->cancelled_at = now();
        $this->cancelled_by = $userId;

        $saved = $this->save();

        if ($saved) {
            $this->logs()->create([
                'toko_id' => $this->toko_id,
                'counter_id' => $this->counter_id,
                'action' => AntrianLog::ACTION_CANCEL,
                'status_before' => $statusBefore,
                'status_after' => self::STATUS_CANCELLED,
                'action_by' => $userId,
                'action_at' => now(),
            ]);

            if ($this->isBooking() && $this->booking) {
                $this->booking->markCancelled();
            }
        }

        return $saved;
    }
}