<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Assessment extends Model
{
    use \Illuminate\Database\Eloquent\SoftDeletes;

    protected $guarded = [];

    protected $casts = [
        'assessment_date' => 'date',
        'max_score' => 'decimal:2',
        'weight' => 'decimal:2',
    ];

    public function classSubjectTeacher(): BelongsTo
    {
        return $this->belongsTo(ClassSubjectTeacher::class);
    }

    public function term(): BelongsTo
    {
        return $this->belongsTo(Term::class);
    }

    public function type(): BelongsTo
    {
        return $this->belongsTo(AssessmentType::class, 'assessment_type_id');
    }

    public function grades(): HasMany
    {
        return $this->hasMany(Grade::class);
    }
}
