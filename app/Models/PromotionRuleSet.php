<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PromotionRuleSet extends Model
{
    public $timestamps = false;

    protected $guarded = [];

    public function rules(): HasMany
    {
        return $this->hasMany(PromotionRule::class, 'rule_set_id')->orderBy('priority');
    }
}
