<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class GradingPolicy extends Model
{
    protected $guarded = [];

    protected $casts = [
        'term_weights' => 'array',
        'promotion_rules' => 'array',
        'averaging_params' => 'array',
        'ranking_enabled' => 'boolean',
        'show_rank_on_report_card' => 'boolean',
        'is_active' => 'boolean',
        'locked_at' => 'datetime',
        'pass_mark' => 'decimal:2',
        'eliminatory_mark' => 'decimal:2',
        'support_threshold' => 'decimal:2',
    ];

    public function academicYear(): BelongsTo
    {
        return $this->belongsTo(AcademicYear::class);
    }

    public function level(): BelongsTo
    {
        return $this->belongsTo(Level::class);
    }

    public function scale(): BelongsTo
    {
        return $this->belongsTo(GradingScale::class, 'scale_id');
    }

    public function subjectRules(): HasMany
    {
        return $this->hasMany(GradingPolicySubjectRule::class, 'policy_id');
    }

    public function ruleSets(): HasMany
    {
        return $this->hasMany(PromotionRuleSet::class, 'policy_id');
    }

    public function isLocked(): bool
    {
        return $this->locked_at !== null;
    }
}
