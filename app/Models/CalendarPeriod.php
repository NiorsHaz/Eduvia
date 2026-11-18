<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CalendarPeriod extends Model
{
    public $timestamps = false;

    protected $guarded = [];

    protected $casts = [
        'start_date' => 'date',
        'end_date' => 'date',
        'created_at' => 'datetime',
    ];
}
