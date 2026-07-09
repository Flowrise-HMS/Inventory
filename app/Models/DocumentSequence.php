<?php

namespace Modules\Inventory\Models;

use Illuminate\Database\Eloquent\Model;

class DocumentSequence extends Model
{
    protected $fillable = [
        'prefix',
        'branch_id',
        'date',
        'sequence',
    ];

    protected $casts = [
        'sequence' => 'integer',
        'date' => 'date',
    ];
}
