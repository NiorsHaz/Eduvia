<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ReminderSchedule extends Model
{
    protected $guarded = [];
    public $timestamps = false;

    protected $casts = [
        'channels' => 'array',
    ];
}
