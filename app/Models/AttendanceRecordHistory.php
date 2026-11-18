<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AttendanceRecordHistory extends Model
{
    protected $table = 'attendance_record_history';

    protected $guarded = [];
    public $timestamps = false;
}
