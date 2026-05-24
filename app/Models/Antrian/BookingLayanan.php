<?php

namespace App\Models\Antrian;

use App\Models\Master\MasterToko;
use App\Models\Pasien;
use App\Models\Master\MasterKaryawan;
use App\Models\Master\MasterTreatment;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class BookingLayanan extends BaseAntrianModel
{
    protected $table = 'booking_layanan';

    protected $primaryKey = 'id';

    public const STATUS_BOOKED = 'booked';
    public const STATUS_CONFIRMED = 'confirmed';
    public const STATUS_CHECKED_IN = 'checked_in';
    public const STATUS_IN_QUEUE = 'in_queue';
    public const STATUS_CALLED = 'called';
    public const STATUS_SERVING = 'serving';
    public const STATUS_COMPLETED = 'completed';
    public const STATUS_CANCELLED = 'cancelled';
    public const STATUS_NO_SHOW = 'no_show';
    public const STATUS_RESCHEDULED = 'rescheduled';
    public const STATUS_LATE = 'late';

    protected $casts = [
        'toko_id' => 'integer',
        'pasien_id' => 'integer',
        'kategori_id' => 'integer',
        'dokter_id' => 'integer',
        'treatment_id' => 'integer',
        'booking_date' => 'date:Y-m-d',
        'booking_time' => 'datetime:H:i:s',
        'appointment_at' => 'datetime',
        'checked_in_at' => 'datetime',
        'cancelled_at' => 'datetime',
        'completed_at' => 'datetime',
        'created_by' => 'integer',
        'updated_by' => 'integer',
    ];

    public function toko(): BelongsTo
    {
        return $this->belongsTo(MasterToko::class, 'toko_id', 'id');
    }

    public function pasien(): BelongsTo
    {
        return $this->belongsTo(Pasien::class, 'pasien_id', 'id');
    }

    public function kategori(): BelongsTo
    {
        return $this->belongsTo(MasterAntrianKategori::class, 'kategori_id', 'id');
    }

    public function dokter(): BelongsTo
    {
        return $this->belongsTo(MasterKaryawan::class, 'dokter_id', 'id');
    }

    public function treatment(): BelongsTo
    {
        return $this->belongsTo(MasterTreatment::class, 'treatment_id', 'id');
    }

    public function antrian(): HasMany
    {
        return $this->hasMany(Antrian::class, 'source_id', 'id')
            ->where('source_type', Antrian::SOURCE_BOOKING);
    }

    public function scopeHariIni($query)
    {
        return $query->whereDate('booking_date', now()->toDateString());
    }

    public function scopeByTanggal($query, $tanggal)
    {
        if (!$tanggal) {
            return $query;
        }

        return $query->whereDate('booking_date', $tanggal);
    }

    public function scopeAktif($query)
    {
        return $query->whereNotIn('status', [
            self::STATUS_COMPLETED,
            self::STATUS_CANCELLED,
            self::STATUS_NO_SHOW,
            self::STATUS_RESCHEDULED,
        ]);
    }

    public function scopeBelumCheckIn($query)
    {
        return $query->whereIn('status', [
            self::STATUS_BOOKED,
            self::STATUS_CONFIRMED,
            self::STATUS_LATE,
        ]);
    }

    public function scopeSudahCheckIn($query)
    {
        return $query->whereIn('status', [
            self::STATUS_CHECKED_IN,
            self::STATUS_IN_QUEUE,
            self::STATUS_CALLED,
            self::STATUS_SERVING,
        ]);
    }

    public function scopeSearchPasien($query, $keyword)
    {
        if (!$keyword) {
            return $query;
        }

        return $query->where(function ($q) use ($keyword) {
            $q->where('booking_code', 'LIKE', "%{$keyword}%")
                ->orWhere('nama_pasien', 'LIKE', "%{$keyword}%")
                ->orWhere('no_hp', 'LIKE', "%{$keyword}%");
        });
    }

    public function markCheckedIn()
    {
        $this->status = self::STATUS_CHECKED_IN;
        $this->checked_in_at = now();

        return $this->save();
    }

    public function markInQueue()
    {
        $this->status = self::STATUS_IN_QUEUE;

        return $this->save();
    }

    public function markCalled()
    {
        $this->status = self::STATUS_CALLED;

        return $this->save();
    }

    public function markServing()
    {
        $this->status = self::STATUS_SERVING;

        return $this->save();
    }

    public function markCompleted()
    {
        $this->status = self::STATUS_COMPLETED;
        $this->completed_at = now();

        return $this->save();
    }

    public function markCancelled()
    {
        $this->status = self::STATUS_CANCELLED;
        $this->cancelled_at = now();

        return $this->save();
    }

    public function markNoShow()
    {
        $this->status = self::STATUS_NO_SHOW;

        return $this->save();
    }

    public function markLate()
    {
        $this->status = self::STATUS_LATE;

        return $this->save();
    }
}