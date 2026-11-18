<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AcademicYear;
use App\Models\GradingPolicy;
use App\Models\GradingPolicySubjectRule;
use App\Models\GradingScale;
use App\Models\PromotionRule;
use App\Models\PromotionRuleSet;
use App\Models\PromotionRuleSetLevel;
use App\Models\RuleMetric;
use App\Models\Setting;
use App\Models\Term;
use App\Services\PolicyService;
use App\Services\SettingService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

class SettingsController extends Controller
{
    public function __construct(
        private readonly SettingService $settings,
        private readonly PolicyService $policies,
    ) {}

    /** The administration screen is generated from the registry. */
    public function registry(Request $request)
    {
        $scope = [
            'campus_id' => $request->filled('campus_id') ? $request->integer('campus_id') : null,
            'academic_year_id' => $request->filled('academic_year_id') ? $request->integer('academic_year_id') : null,
            'level_id' => $request->filled('level_id') ? $request->integer('level_id') : null,
        ];

        $resolved = $this->settings->all($scope);

        $definitions = $this->settings->definitions()->map(fn ($definition) => [
            'key' => $definition->key,
            'module' => $definition->module,
            'label_key' => $definition->label_key,
            'description_key' => $definition->description_key,
            'value_type' => $definition->value_type,
            'allowed_values' => $definition->allowed_values,
            'default_value' => $definition->default_value,
            'allowed_scopes' => $definition->allowed_scopes,
            'value' => $resolved[$definition->key] ?? $definition->default_value,
            'is_default' => ! Setting::where('key', $definition->key)
                ->where('campus_id', $scope['campus_id'])
                ->where('academic_year_id', $scope['academic_year_id'])
                ->where('level_id', $scope['level_id'])->exists(),
        ])->groupBy('module');

        return ['scope' => $scope, 'modules' => $definitions];
    }

    public function updateSetting(Request $request)
    {
        $data = $request->validate([
            'key' => 'required|string|exists:setting_definitions,key',
            'value' => 'required',
            'scope' => 'nullable|array',
            'scope.campus_id' => 'nullable|integer|exists:campuses,id',
            'scope.academic_year_id' => 'nullable|integer|exists:academic_years,id',
            'scope.level_id' => 'nullable|integer|exists:levels,id',
        ]);

        try {
            $setting = $this->settings->set($data['key'], $data['value'], $data['scope'] ?? [], $request->user()->id);
        } catch (InvalidArgumentException $e) {
            throw ValidationException::withMessages(['value' => $e->getMessage()]);
        }

        return $setting;
    }

    public function policies(Request $request)
    {
        return GradingPolicy::with(['academicYear', 'level', 'scale'])
            ->withCount(['subjectRules', 'ruleSets'])
            ->when($request->filled('academic_year_id'), fn ($q) => $q->where('academic_year_id', $request->integer('academic_year_id')))
            ->orderByDesc('academic_year_id')->orderByDesc('is_active')
            ->get();
    }

    /** Create a policy, optionally starting from the standard /20 preset. */
    public function storePolicy(Request $request)
    {
        $data = $this->validatePolicy($request);
        $data['name'] = $data['name'] ?? 'Standard policy';
        $data['scale_id'] = $data['scale_id'] ?? GradingScale::orderBy('max_score')->value('id');

        if (! $data['scale_id']) {
            throw ValidationException::withMessages(['scale_id' => 'Create a grading scale first (Rules → Grading scales).']);
        }

        $policy = DB::transaction(function () use ($data, $request) {
            $policy = GradingPolicy::create($data + [
                'version' => 1,
                'is_active' => true,
            ]);

            if (! empty($data['term_weights']) === false) {
                $policy->term_weights = Term::where('academic_year_id', $policy->academic_year_id)
                    ->pluck('id')->mapWithKeys(fn ($id) => [(string) $id => 1])->all();
                $policy->save();
            }

            $this->syncSubjectRules($policy, $request->input('subject_rules', []));

            if ($request->boolean('with_standard_promotion_rules', true)) {
                $this->createStandardRuleSet($policy);
            }

            return $policy;
        });

        activity('settings')->performedOn($policy)->log('grading_policy.created');

        return response()->json($policy->load(['scale', 'subjectRules', 'ruleSets.rules']), 201);
    }

    public function updatePolicy(Request $request, GradingPolicy $policy)
    {
        $this->policies->assertEditable($policy);
        $data = $this->validatePolicy($request, true);

        DB::transaction(function () use ($policy, $data, $request) {
            $policy->update($data);
            if ($request->has('subject_rules')) {
                $this->syncSubjectRules($policy, $request->input('subject_rules', []));
            }
        });

        return $policy->fresh(['scale', 'subjectRules', 'ruleSets.rules']);
    }

    public function showPolicy(GradingPolicy $policy)
    {
        return $policy->load(['scale', 'subjectRules.subject', 'ruleSets.rules', 'academicYear', 'level']);
    }

    public function lockPolicy(Request $request, GradingPolicy $policy)
    {
        $data = $request->validate(['reason' => 'required|string|max:255']);
        $this->policies->lock($policy, $data['reason']);

        return $policy->fresh();
    }

    public function clonePolicy(Request $request, GradingPolicy $policy)
    {
        $data = $request->validate([
            'target_academic_year_id' => 'required|integer|exists:academic_years,id',
        ]);

        $copy = $this->policies->clone($policy, (int) $data['target_academic_year_id']);
        $target = AcademicYear::find($data['target_academic_year_id']);

        // Term weights follow the target year's terms.
        $copy->term_weights = Term::where('academic_year_id', $copy->academic_year_id)
            ->pluck('id')->mapWithKeys(fn ($id) => [(string) $id => 1])->all();
        $copy->name = $policy->name.' ('.$target->name.')';
        $copy->save();

        return response()->json($copy->load(['scale', 'subjectRules', 'ruleSets.rules']), 201);
    }

    public function subjectRules(GradingPolicy $policy)
    {
        return GradingPolicySubjectRule::with('subject')->where('policy_id', $policy->id)->get();
    }

    public function storeSubjectRule(Request $request, GradingPolicy $policy)
    {
        $this->policies->assertEditable($policy);

        $data = $request->validate([
            'subject_id' => 'required|integer|exists:subjects,id',
            'scale_id' => 'nullable|integer|exists:grading_scales,id',
            'pass_mark' => 'nullable|numeric|min:0',
            'eliminatory_mark' => 'nullable|numeric|min:0',
            'counts_in_average' => 'boolean',
            'bonus_above' => 'nullable|numeric|min:0',
            'is_core' => 'boolean',
        ]);

        return GradingPolicySubjectRule::updateOrCreate(
            ['policy_id' => $policy->id, 'subject_id' => $data['subject_id']],
            $data
        );
    }

    public function destroySubjectRule(GradingPolicy $policy, GradingPolicySubjectRule $rule)
    {
        $this->policies->assertEditable($policy);
        abort_unless((int) $rule->policy_id === (int) $policy->id, 404);
        $rule->delete();

        return response()->noContent();
    }

    public function promotionRules(GradingPolicy $policy)
    {
        return PromotionRuleSet::with(['rules'])
            ->where('policy_id', $policy->id)->get()
            ->map(fn (PromotionRuleSet $set) => [
                'id' => $set->id,
                'name' => $set->name,
                'description' => $set->description,
                'levels' => PromotionRuleSetLevel::where('rule_set_id', $set->id)->pluck('level_id'),
                'rules' => $set->rules,
            ]);
    }

    public function storeRuleSet(Request $request, GradingPolicy $policy)
    {
        $this->policies->assertEditable($policy);
        $data = $request->validate([
            'name' => 'required|string|max:120',
            'description' => 'nullable|string',
            'level_id' => 'nullable|integer|exists:levels,id',
        ]);

        $set = PromotionRuleSet::create([
            'policy_id' => $policy->id,
            'name' => $data['name'],
            'description' => $data['description'] ?? null,
        ]);
        PromotionRuleSetLevel::create(['rule_set_id' => $set->id, 'level_id' => $data['level_id'] ?? null]);

        return response()->json($set->load('rules'), 201);
    }

    public function storePromotionRule(Request $request, PromotionRuleSet $ruleSet)
    {
        $policy = GradingPolicy::findOrFail($ruleSet->policy_id);
        $this->policies->assertEditable($policy);

        $data = $request->validate([
            'priority' => 'required|integer|min:1',
            'label' => 'required|string|max:160',
            'condition' => 'required|array',
            'outcome' => 'required|in:promoted,repeat,conditional,graduated,left,council_review',
            'requires_human_confirmation' => 'boolean',
            'is_active' => 'boolean',
        ]);

        $this->validateConditionMetrics($data['condition']);

        return response()->json(PromotionRule::create($data + ['rule_set_id' => $ruleSet->id]), 201);
    }

    public function updatePromotionRule(Request $request, PromotionRule $rule)
    {
        $ruleSet = PromotionRuleSet::findOrFail($rule->rule_set_id);
        $this->policies->assertEditable(GradingPolicy::findOrFail($ruleSet->policy_id));

        $data = $request->validate([
            'priority' => 'integer|min:1',
            'label' => 'string|max:160',
            'condition' => 'array',
            'outcome' => 'in:promoted,repeat,conditional,graduated,left,council_review',
            'requires_human_confirmation' => 'boolean',
            'is_active' => 'boolean',
        ]);

        if (isset($data['condition'])) {
            $this->validateConditionMetrics($data['condition']);
        }

        $rule->update($data);

        return $rule;
    }

    public function destroyPromotionRule(PromotionRule $rule)
    {
        $ruleSet = PromotionRuleSet::findOrFail($rule->rule_set_id);
        $this->policies->assertEditable(GradingPolicy::findOrFail($ruleSet->policy_id));
        $rule->delete();

        return response()->noContent();
    }

    public function metrics()
    {
        return RuleMetric::all();
    }

    private function validateConditionMetrics(array $condition): void
    {
        $walk = function (array $node) use (&$walk) {
            if (isset($node['metric'])) {
                if (! RuleMetric::whereKey($node['metric'])->exists()) {
                    throw ValidationException::withMessages(['condition' => "Metric [{$node['metric']}] is not whitelisted."]);
                }
            }
            foreach (['all', 'any'] as $group) {
                foreach ($node[$group] ?? [] as $child) {
                    $walk($child);
                }
            }
            if (isset($node['not'])) {
                $walk($node['not']);
            }
        };
        $walk($condition);
    }

    private function syncSubjectRules(GradingPolicy $policy, array $rules): void
    {
        $keep = [];
        foreach ($rules as $rule) {
            if (empty($rule['subject_id'])) {
                continue;
            }
            $row = GradingPolicySubjectRule::updateOrCreate(
                ['policy_id' => $policy->id, 'subject_id' => $rule['subject_id']],
                [
                    'scale_id' => $rule['scale_id'] ?? null,
                    'pass_mark' => $rule['pass_mark'] ?? null,
                    'eliminatory_mark' => $rule['eliminatory_mark'] ?? null,
                    'counts_in_average' => $rule['counts_in_average'] ?? true,
                    'bonus_above' => $rule['bonus_above'] ?? null,
                    'is_core' => $rule['is_core'] ?? false,
                ]
            );
            $keep[] = $row->id;
        }

        GradingPolicySubjectRule::where('policy_id', $policy->id)->whereNotIn('id', $keep ?: [0])->delete();
    }

    private function createStandardRuleSet(GradingPolicy $policy): void
    {
        $set = PromotionRuleSet::create([
            'policy_id' => $policy->id,
            'name' => 'Standard promotion rules',
            'description' => 'Average of 10 or more and no eliminatory subject → promoted; 9 to 10 → council review; otherwise repeat.',
        ]);
        PromotionRuleSetLevel::create(['rule_set_id' => $set->id, 'level_id' => null]);

        $rules = [
            [
                'priority' => 10,
                'label' => 'Average of 10 or more and no eliminatory subject',
                'condition' => ['all' => [
                    ['metric' => 'annual_average', 'op' => '>=', 'value' => 10],
                    ['metric' => 'subjects_below_eliminatory', 'op' => '=', 'value' => 0],
                ]],
                'outcome' => 'promoted',
                'requires_human_confirmation' => true,
            ],
            [
                'priority' => 20,
                'label' => 'Average between 9 and 10',
                'condition' => ['all' => [
                    ['metric' => 'annual_average', 'op' => '>=', 'value' => 9],
                    ['metric' => 'annual_average', 'op' => '<', 'value' => 10],
                ]],
                'outcome' => 'council_review',
                'requires_human_confirmation' => true,
            ],
            [
                'priority' => 90,
                'label' => 'Otherwise',
                'condition' => ['all' => []],
                'outcome' => 'repeat',
                'requires_human_confirmation' => true,
            ],
        ];

        foreach ($rules as $rule) {
            PromotionRule::create($rule + ['rule_set_id' => $set->id, 'is_active' => true]);
        }
    }

    private function validatePolicy(Request $request, bool $partial = false): array
    {
        $required = $partial ? 'sometimes' : 'required';

        $rules = [
            'academic_year_id' => $required.'|integer|exists:academic_years,id',
            'level_id' => 'nullable|integer|exists:levels,id',
            'scale_id' => 'nullable|integer|exists:grading_scales,id',
            'name' => 'nullable|string|max:120',
            'pass_mark' => 'numeric|min:0',
            'rounding_decimals' => 'integer|between:0,4',
            'rounding_mode' => 'in:half_up,down,up',
            'ranking_enabled' => 'boolean',
            'term_weights' => 'nullable|array',
            'absence_handling' => 'in:ignore,zero,zero_if_unexcused',
            'missing_grade_handling' => 'in:ignore,zero,block_validation',
            'averaging_method' => 'in:weighted,simple_mean,best_n,drop_lowest',
            'averaging_params' => 'nullable|array',
            'subject_aggregation' => 'in:coefficient_weighted,simple_mean',
            'term_aggregation' => 'in:weighted_terms,simple_mean,last_term_only',
            'eliminatory_mark' => 'nullable|numeric|min:0',
            'min_graded_assessments' => 'integer|min:0',
            'ranking_scope' => 'in:class,level',
            'ranking_ties' => 'in:shared,break_by_coefficient_subjects',
            'show_rank_on_report_card' => 'boolean',
            'support_threshold' => 'nullable|numeric|min:0',
        ];

        $data = $request->validate($rules);

        if ($partial) {
            unset($data['academic_year_id'], $data['level_id']);
        }

        return $data;
    }
}
