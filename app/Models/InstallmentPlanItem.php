<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class InstallmentPlanItem extends Model
{
    public $timestamps = false;

    protected $guarded = [];

    protected $casts = [
        'percentage' => 'decimal:2',
        'due_date' => 'date',
    ];
}
