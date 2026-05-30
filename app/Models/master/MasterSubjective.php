<?php

namespace App\Models\Master;

class MasterSubjective extends BaseMasterModel
{
    protected $table = 'master_subjective';

    protected $casts = [
        'sort_order' => 'integer',
        'is_active' => 'integer',
        'is_delete' => 'integer',
    ];
}