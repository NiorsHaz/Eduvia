<?php

namespace App\Services;

use App\Models\AnnualResult;
use App\Models\Classroom;
use App\Models\GradingPolicy;
use App\Models\PromotionRule;
use App\Models\PromotionRuleSet;
use App\Models\RuleMetric;
use App\Models\Student;
use Illuminate\Support\Collection;
use InvalidArgumentException;

/**
 * Evaluates promotion rules stored as structured data. Conditions are read by
 * a whitelisted evaluator — never executed as code or SQL.
 */
class PromotionEvaluator
{
    public function __construct(private readonly GradeCalculator $calculator) {}

    public function ruleSetFor(GradingPolicy $policy, ?int $levelId): ?PromotionRuleSet
    {
        $sets = PromotionRuleSet::where('policy_id', $policy->id)->get();

        return $sets->first(function (PromotionRuleSet $set) use ($levelId) {
            $levels = \App\Models\PromotionRuleSetLevel::where('rule_set_id', $set->id)->pluck('level_id');

            return $levels->contains($levelId) || $levels->contains(null);
        }) ?? $sets->first();
    }

    public function rulesFor(GradingPolicy $policy, ?int $levelId): Collection
    {
        $set = $this->ruleSetFor($policy, $levelId);

        return $set
            ? PromotionRule::where('rule_set_id', $set->id)->where('is_active', true)->orderBy('priority')->get()
            : collect();
    }

    /**
     * @return array{outcome:string, rule:?PromotionRule, requires_confirmation:bool, matched_label:?string}
     */
    public function evaluate(array $metrics, Collection $rules): array
    {
        foreach ($rules as $rule) {
            if ($this->matches($rule->condition ?? [], $metrics)) {
                return [
                    'outcome' => $rule->outcome,
                    'rule' => $rule,
                    'requires_confirmation' => (bool) $rule->requires_human_confirmation,
                    'matched_label' => $rule->label,
                ];
            }
        }

        return ['outcome' => 'pending', 'rule' => null, 'requires_confirmation' => true, 'matched_label' => null];
    }

    /** Suggest and persist a decision for every student of a class. */
    public function applyToClass(Classroom $class): array
    {
        $policy = $this->calculator->policyFor($class->academic_year_id, $class->level_id);
        $rules = $this->rulesFor($policy, $class->level_id);
        $this->calculator->computeAnnual($class->id, $class->academic_year_id);

        $students = $class->students()->whereNull('students.deleted_at')->get();
        $results = [];

        foreach ($students as $student) {
            $metrics = $this->calculator->metricsFor($student, $class->id, $class->academic_year_id);
            $decision = $this->evaluate($metrics, $rules);

            $annual = AnnualResult::firstOrNew([
                'student_id' => $student->id,
                'academic_year_id' => $class->academic_year_id,
            ]);
            $annual->class_id = $class->id;
            $annual->policy_id = $policy->id;
            $annual->promotion_rule_id = $decision['rule']?->id;
            $annual->decision = $decision['outcome'];
            $annual->decision_source = 'rule';
            $annual->decision_note = $decision['matched_label']
                ? 'Suggested by rule: '.$decision['matched_label']
                : 'No rule matched.';
            $annual->decided_by = null;
            $annual->decided_at = null;
            $annual->save();

            $results[] = [
                'student_id' => $student->id,
                'student' => $student->first_name.' '.$student->last_name,
                'average' => $annual->average,
                'decision' => $decision['outcome'],
                'rule' => $decision['matched_label'],
                'requires_confirmation' => $decision['requires_confirmation'],
            ];
        }

        return $results;
    }

    /** An authorized person confirms or overrides the suggestion. */
    public function confirm(AnnualResult $result, string $decision, int $userId, ?string $note = null): AnnualResult
    {
        $allowed = ['promoted', 'repeat', 'conditional', 'graduated', 'left', 'council_review'];
        if (! in_array($decision, $allowed, true)) {
            throw new InvalidArgumentException('Unknown promotion decision.');
        }

        $result->update([
            'decision' => $decision,
            'decision_source' => 'manual',
            'decision_note' => $note ?? $result->decision_note,
            'decided_by' => $userId,
            'decided_at' => now(),
        ]);

        activity('academics')->performedOn($result)->withProperties(['decision' => $decision])->log('promotion.confirmed');

        return $result;
    }

    public function matches(array $condition, array $metrics): bool
    {
        if (array_key_exists('all', $condition)) {
            foreach ($condition['all'] as $child) {
                if (! $this->matches($child, $metrics)) {
                    return false;
                }
            }

            return true;
        }

        if (array_key_exists('any', $condition)) {
            foreach ($condition['any'] as $child) {
                if ($this->matches($child, $metrics)) {
                    return true;
                }
            }

            return false;
        }

        if (array_key_exists('not', $condition)) {
            return ! $this->matches($condition['not'], $metrics);
        }

        $metric = $condition['metric'] ?? null;
        if (! $metric) {
            return false;
        }

        if (! RuleMetric::whereKey($metric)->exists()) {
            throw new InvalidArgumentException("Rule metric [{$metric}] is not whitelisted.");
        }

        $actual = $metrics[$metric] ?? null;
        if (is_callable($actual)) {
            $actual = $actual($condition['params'] ?? []);
        }
        if ($actual === null) {
            return false;
        }

        $expected = $condition['value'] ?? null;

        return match ($condition['op'] ?? '=') {
            '=' , '==' => $actual == $expected,
            '!=' => $actual != $expected,
            '>' => $actual > $expected,
            '>=' => $actual >= $expected,
            '<' => $actual < $expected,
            '<=' => $actual <= $expected,
            'in' => in_array($actual, (array) $expected, true),
            default => false,
        };
    }
}
