<?php

namespace App\Models\Master;

class MasterAssessment extends BaseMasterModel
{
    protected $table = 'master_assessment';

    protected $casts = [
        'sort_order' => 'integer',
        'is_active' => 'integer',
        'is_delete' => 'integer',
    ];
}