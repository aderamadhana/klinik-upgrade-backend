<?php

namespace App\Models\Master;

class MasterAccurateItemMapping extends BaseMasterModel
{
    protected $table = 'master_accurate_item_mapping';

    protected $guarded = [];

    public $timestamps = true;

    protected $casts = [
        'legacy_treatment_id' => 'integer',
        'default_harga' => 'decimal:2',
        'is_billable' => 'integer',
        'is_send_to_accurate' => 'integer',
        'send_when_zero' => 'integer',
        'sort_order' => 'integer',
        'is_active' => 'integer',
        'is_delete' => 'integer',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    public function legacyTreatment()
    {
        return $this->belongsTo(MasterTreatment::class, 'legacy_treatment_id');
    }

    public function scopeActive($query)
    {
        return $query->where(function ($q) {
                $q->where('is_delete', 0)->orWhereNull('is_delete');
            })
            ->where(function ($q) {
                $q->where('is_active', 1)->orWhereNull('is_active');
            });
    }

    public function scopeSendable($query)
    {
        return $query->active()->where('is_send_to_accurate', 1);
    }

    public function scopeSource($query, string $sourceType, string $sourceCode)
    {
        return $query->where('source_type', $sourceType)
            ->where('source_code', $sourceCode);
    }
}
