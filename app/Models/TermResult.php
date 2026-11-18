<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TermResult extends Model
{
    public $timestamps = false;

    protected $guarded = [];

    protected $casts = [
        'computed_at' => 'datetime',
        'average' => 'decimal:2',
        'class_average' => 'decimal:2',
        'needs_support' => 'boolean',
    ];

    public function student(): BelongsTo
    {
        return $this->belongsTo(Student::class);
    }

    public function classroom(): BelongsTo
    {
        return $this->belongsTo(Classroom::class, 'class_id');
    }

    public function term(): BelongsTo
    {
        return $this->belongsTo(Term::class);
    }
}
