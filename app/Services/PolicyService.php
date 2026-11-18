<?php

namespace App\Services;

use App\Models\GradingPolicy;
use App\Models\GradingPolicySubjectRule;
use App\Models\PromotionRule;
use App\Models\PromotionRuleSet;
use App\Models\PromotionRuleSetLevel;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class PolicyService
{
    public function assertEditable(GradingPolicy $policy): void
    {
        if ($policy->isLocked()) {
            throw ValidationException::withMessages([
                'policy' => 'This policy is locked because results were validated under it. Create a new version instead.',
            ]);
        }
    }

    public function lock(GradingPolicy $policy, string $reason): void
    {
        if ($policy->locked_at) {
            return;
        }

        $policy->update(['locked_at' => now(), 'locked_reason' => $reason]);
        activity('academics')->performedOn($policy)->withProperties(['reason' => $reason])->log('policy.locked');
    }

    /**
     * Copy a policy (subject rules and promotion rules included) into a new
     * version or a new academic year. Mirrors fn_clone_grading_policy().
     */
    public function clone(GradingPolicy $source, int $targetYearId): GradingPolicy
    {
        return DB::transaction(function () use ($source, $targetYearId) {
            $sameYear = (int) $targetYearId === (int) $source->academic_year_id;

            if ($sameYear) {
                $source->update(['is_active' => false]);
            }

            $copy = $source->replicate([
                'term_weights', 'locked_at', 'locked_reason', 'created_at', 'updated_at',
            ]);
            $copy->academic_year_id = $targetYearId;
            $copy->version = $sameYear ? $source->version + 1 : 1;
            $copy->is_active = true;
            $copy->locked_at = null;
            $copy->locked_reason = null;
            $copy->term_weights = [];
            $copy->save();

            foreach ($source->subjectRules as $rule) {
                GradingPolicySubjectRule::create([
                    'policy_id' => $copy->id,
                    'subject_id' => $rule->subject_id,
                    'scale_id' => $rule->scale_id,
                    'pass_mark' => $rule->pass_mark,
                    'eliminatory_mark' => $rule->eliminatory_mark,
                    'counts_in_average' => $rule->counts_in_average,
                    'bonus_above' => $rule->bonus_above,
                    'is_core' => $rule->is_core,
                ]);
            }

            foreach ($source->ruleSets as $set) {
                $newSet = PromotionRuleSet::create([
                    'policy_id' => $copy->id,
                    'name' => $set->name,
                    'description' => $set->description,
                ]);

                foreach ($set->rules ?? PromotionRule::where('rule_set_id', $set->id)->get() as $rule) {
                    PromotionRule::create([
                        'rule_set_id' => $newSet->id,
                        'priority' => $rule->priority,
                        'label' => $rule->label,
                        'condition' => $rule->condition,
                        'outcome' => $rule->outcome,
                        'requires_human_confirmation' => $rule->requires_human_confirmation,
                        'is_active' => $rule->is_active,
                    ]);
                }

                $levels = PromotionRuleSetLevel::where('rule_set_id', $set->id)->pluck('level_id');
                if ($levels->isEmpty()) {
                    PromotionRuleSetLevel::create(['rule_set_id' => $newSet->id, 'level_id' => null]);
                } else {
                    foreach ($levels as $levelId) {
                        PromotionRuleSetLevel::create(['rule_set_id' => $newSet->id, 'level_id' => $levelId]);
                    }
                }
            }

            return $copy;
        });
    }
}
