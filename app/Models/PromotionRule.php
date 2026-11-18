<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PromotionRule extends Model
{
    public $timestamps = false;

    protected $guarded = [];

    protected $casts = [
        'condition' => 'array',
        'requires_human_confirmation' => 'boolean',
        'is_active' => 'boolean',
    ];

    public function ruleSet(): BelongsTo
    {
        return $this->belongsTo(PromotionRuleSet::class);
    }
}
