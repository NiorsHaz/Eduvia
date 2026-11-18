<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CashClosing extends Model
{
    public $timestamps = false;

    protected $guarded = [];

    protected $casts = [
        'closing_date' => 'date',
        'closed_at' => 'datetime',
        'expected_amount' => 'integer',
        'counted_amount' => 'integer',
        'difference' => 'integer',
    ];
}
