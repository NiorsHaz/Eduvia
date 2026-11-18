<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Classroom extends Model
{
    use SoftDeletes;

    protected $table = 'classes';

    protected $guarded = [];

    public function academicYear(): BelongsTo
    {
        return $this->belongsTo(AcademicYear::class);
    }

    public function level(): BelongsTo
    {
        return $this->belongsTo(Level::class);
    }

    public function track(): BelongsTo
    {
        return $this->belongsTo(Track::class);
    }

    public function supervisor(): BelongsTo
    {
        return $this->belongsTo(Staff::class, 'supervisor_staff_id');
    }

    public function homeRoom(): BelongsTo
    {
        return $this->belongsTo(Room::class, 'home_room_id');
    }

    public function enrollments(): HasMany
    {
        return $this->hasMany(Enrollment::class, 'class_id');
    }

    public function subjectTeachers(): HasMany
    {
        return $this->hasMany(ClassSubjectTeacher::class, 'class_id');
    }

    public function students(): BelongsToMany
    {
        return $this->belongsToMany(Student::class, 'enrollments', 'class_id', 'student_id')
            ->wherePivotNull('end_date')
            ->withPivot(['start_date', 'end_date', 'status']);
    }

    public function getLabelAttribute(): string
    {
        return trim(($this->level?->code ? $this->level->code.' ' : '').$this->name);
    }
}
