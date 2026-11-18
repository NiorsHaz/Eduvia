<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Subject extends Model
{
    public $timestamps = false;

    protected $guarded = [];

    protected $casts = [
        'is_optional' => 'boolean',
        'is_active' => 'boolean',
    ];

    public function classTeachers(): HasMany
    {
        return $this->hasMany(ClassSubjectTeacher::class);
    }
}
