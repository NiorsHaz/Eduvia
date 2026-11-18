<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ReminderLog extends Model
{
    protected $table = 'reminder_log';

    protected $guarded = [];
    public $timestamps = false;
}
