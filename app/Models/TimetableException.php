<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TimetableException extends Model
{
    public $timestamps = false;

    protected $guarded = [];

    protected $casts = [
        'exception_date' => 'date',
        'created_at' => 'datetime',
    ];

    public function entry(): BelongsTo
    {
        return $this->belongsTo(TimetableEntry::class, 'timetable_entry_id');
    }

    public function substituteStaff(): BelongsTo
    {
        return $this->belongsTo(Staff::class, 'substitute_staff_id');
    }

    public function substituteRoom(): BelongsTo
    {
        return $this->belongsTo(Room::class, 'substitute_room_id');
    }
}
