<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class GradingPolicySubjectRule extends Model
{
    public $timestamps = false;

    protected $guarded = [];

    protected $casts = [
        'counts_in_average' => 'boolean',
        'is_core' => 'boolean',
        'pass_mark' => 'decimal:2',
        'eliminatory_mark' => 'decimal:2',
    ];

    public function subject(): BelongsTo
    {
        return $this->belongsTo(Subject::class);
    }

    public function scale(): BelongsTo
    {
        return $this->belongsTo(GradingScale::class, 'scale_id');
    }
}
