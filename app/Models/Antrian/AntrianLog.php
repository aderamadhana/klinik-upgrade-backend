<?php

namespace App\Models\Antrian;

use App\Models\Concerns\Auditable;
use App\Models\Master\MasterToko;
use App\Models\Master\MasterUser;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AntrianLog extends Model
{
    use Auditable;

    protected $table = 'antrian_log';

    protected $primaryKey = 'id';

    protected $guarded = [];

    public $timestamps = true;

    protected $auditModuleName = 'Antrian';

    public const ACTION_CREATED = 'created';
    public const ACTION_CHECKIN = 'checkin';
    public const ACTION_CALL = 'call';
    public const ACTION_RECALL = 'recall';
    public const ACTION_SERVE = 'serve';
    public const ACTION_SKIP = 'skip';
    public const ACTION_FINISH = 'finish';
    public const ACTION_CANCEL = 'cancel';

    protected $casts = [
        'antrian_id' => 'integer',
        'toko_id' => 'integer',
        'counter_id' => 'integer',
        'action_by' => 'integer',
        'action_at' => 'datetime',
    ];

    public function antrian(): BelongsTo
    {
        return $this->belongsTo(Antrian::class, 'antrian_id', 'id');
    }

    public function toko(): BelongsTo
    {
        return $this->belongsTo(MasterToko::class, 'toko_id', 'id');
    }

    public function counter(): BelongsTo
    {
        return $this->belongsTo(MasterAntrianCounter::class, 'counter_id', 'id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(MasterUser::class, 'action_by', 'id');
    }

    public function scopeByAction($query, $action)
    {
        if (!$action) {
            return $query;
        }

        return $query->where('action', $action);
    }

    public function scopeByTanggal($query, $tanggal)
    {
        if (!$tanggal) {
            return $query;
        }

        return $query->whereDate('action_at', $tanggal);
    }

    public function scopeByToko($query, $tokoId)
    {
        if (!$tokoId) {
            return $query;
        }

        return $query->where('toko_id', $tokoId);
    }
}