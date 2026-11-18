<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SubjectTermResult extends Model
{
    public $timestamps = false;

    protected $guarded = [];

    protected $casts = [
        'computed_at' => 'datetime',
        'average' => 'decimal:2',
        'coefficient' => 'decimal:2',
        'class_average' => 'decimal:2',
    ];

    public function student(): BelongsTo
    {
        return $this->belongsTo(Student::class);
    }

    public function subject(): BelongsTo
    {
        return $this->belongsTo(Subject::class);
    }
}
