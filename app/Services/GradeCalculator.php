<?php

namespace App\Services;

use App\Models\AcademicYear;
use App\Models\AnnualResult;
use App\Models\Assessment;
use App\Models\Classroom;
use App\Models\ClassSubjectTeacher;
use App\Models\Enrollment;
use App\Models\Grade;
use App\Models\GradingPolicy;
use App\Models\GradingPolicySubjectRule;
use App\Models\GradingScale;
use App\Models\GradingScaleBand;
use App\Models\Level;
use App\Models\LevelSubject;
use App\Models\Student;
use App\Models\SubjectTermResult;
use App\Models\Term;
use App\Models\TermResult;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Reads the grading policy tables and contains no school-specific rules.
 * Every average is snapshotted with the policy that produced it.
 */
class GradeCalculator
{
    public function policyFor(int $academicYearId, ?int $levelId): GradingPolicy
    {
        $policy = GradingPolicy::query()
            ->where('academic_year_id', $academicYearId)
            ->where('is_active', true)
            ->where(function ($q) use ($levelId) {
                $q->whereNull('level_id');
                if ($levelId) {
                    $q->orWhere('level_id', $levelId);
                }
            })
            ->orderByRaw('level_id IS NULL')
            ->first();

        if (! $policy) {
            throw ValidationException::withMessages([
                'policy' => 'No active grading policy is configured for this academic year. Create one in Rules → Grading policies.',
            ]);
        }

        return $policy;
    }

    /**
     * Compute and snapshot one subject for a whole class in a term.
     *
     * @return array<int, SubjectTermResult>
     */
    public function computeSubjectTerm(int $classId, int $subjectId, int $termId, bool $strict = false): array
    {
        $class = Classroom::with('level')->findOrFail($classId);
        $term = Term::findOrFail($termId);
        $policy = $this->policyFor($class->academic_year_id, $class->level_id);

        $rule = GradingPolicySubjectRule::where('policy_id', $policy->id)
            ->where('subject_id', $subjectId)->first();

        $scaleId = $rule?->scale_id ?: $policy->scale_id;
        $scaleMax = (float) (GradingScale::find($scaleId)?->max_score ?? 20);

        $assignments = ClassSubjectTeacher::where('class_id', $classId)
            ->where('subject_id', $subjectId)->pluck('id');

        $coefficient = (float) (LevelSubject::where('academic_year_id', $class->academic_year_id)
            ->where('level_id', $class->level_id)
            ->where(fn ($q) => $q->whereNull('track_id')->orWhere('track_id', $class->track_id))
            ->where('subject_id', $subjectId)
            ->value('coefficient') ?? 1);

        $assessments = Assessment::query()
            ->whereIn('class_subject_teacher_id', $assignments)
            ->where('term_id', $termId)
            ->orderBy('assessment_date')
            ->orderBy('id')
            ->get();

        $grades = Grade::whereIn('assessment_id', $assessments->pluck('id'))
            ->get()
            ->groupBy('assessment_id')
            ->map(fn (Collection $rows) => $rows->keyBy('student_id'));

        $students = $this->studentsInClass($classId);
        $rows = [];

        foreach ($students as $student) {
            $values = [];
            $blocked = false;

            foreach ($assessments as $assessment) {
                $grade = $grades->get($assessment->id)?->get($student->id);
                $status = $grade->status ?? 'not_graded';

                if ($status === 'graded' && $grade->score !== null) {
                    $values[] = [
                        'value' => (float) $grade->score / max(0.01, (float) $assessment->max_score) * $scaleMax,
                        'weight' => max(0.0, (float) $assessment->weight),
                    ];

                    continue;
                }

                if ($status === 'absent') {
                    if (in_array($policy->absence_handling, ['zero', 'zero_if_unexcused'], true)) {
                        $values[] = ['value' => 0.0, 'weight' => max(0.0, (float) $assessment->weight)];
                    }

                    continue;
                }

                if ($status === 'excused') {
                    continue; // an excused absence never penalises the student
                }

                // no row / not_graded = missing grade
                if ($policy->missing_grade_handling === 'zero') {
                    $values[] = ['value' => 0.0, 'weight' => max(0.0, (float) $assessment->weight)];
                } elseif ($policy->missing_grade_handling === 'block_validation' && $strict) {
                    $blocked = true;
                }
            }

            if ($blocked) {
                throw ValidationException::withMessages([
                    'grades' => "Student #{$student->id} has missing grades and the policy blocks validation.",
                ]);
            }

            $average = count($values) >= max(0, (int) $policy->min_graded_assessments)
                && count($values) > 0
                    ? $this->aggregate($values, $policy)
                    : null;

            if ($average !== null && $rule?->bonus_above !== null) {
                $average = max(0, $average - (float) $rule->bonus_above);
            }

            if ($average !== null) {
                $average = $this->round($average, $policy);
            }

            $rows[] = [
                'student_id' => $student->id,
                'average' => $average,
                'coefficient' => $coefficient,
                'appreciation' => $average !== null ? $this->appreciation($average, $scaleId) : null,
            ];
        }

        $averages = collect($rows)->pluck('average')->filter(fn ($v) => $v !== null);
        $classAverage = $averages->count() ? $this->round($averages->avg(), $policy) : null;
        $classMin = $averages->count() ? $averages->min() : null;
        $classMax = $averages->count() ? $averages->max() : null;
        $ranks = $policy->ranking_enabled ? $this->ranks($rows) : [];

        $saved = [];
        foreach ($rows as $row) {
            $saved[] = SubjectTermResult::updateOrCreate(
                ['student_id' => $row['student_id'], 'subject_id' => $subjectId, 'term_id' => $termId],
                [
                    'class_id' => $classId,
                    'average' => $row['average'],
                    'coefficient' => $row['coefficient'],
                    'class_average' => $classAverage,
                    'class_min' => $classMin,
                    'class_max' => $classMax,
                    'rank' => $ranks[$row['student_id']] ?? null,
                    'appreciation' => $row['appreciation'],
                    'policy_id' => $policy->id,
                ]
            );
        }

        return $saved;
    }

    /** @return array<int, TermResult> */
    public function computeTerm(int $classId, int $termId): array
    {
        $class = Classroom::with('level')->findOrFail($classId);
        $term = Term::with('academicYear')->findOrFail($termId);
        $policy = $this->policyFor($class->academic_year_id, $class->level_id);

        $subjectResults = SubjectTermResult::where('class_id', $classId)->where('term_id', $termId)->get();

        $excluded = GradingPolicySubjectRule::where('policy_id', $policy->id)
            ->where('counts_in_average', false)->pluck('subject_id')->all();

        $attendance = $this->attendanceCounts($classId, $term);
        $students = $this->studentsInClass($classId);
        $rows = [];

        foreach ($students as $student) {
            $results = $subjectResults->where('student_id', $student->id)
                ->whereNotIn('subject_id', $excluded)
                ->filter(fn ($r) => $r->average !== null);

            $weighted = 0.0;
            $weights = 0.0;
            foreach ($results as $result) {
                $weight = $policy->subject_aggregation === 'simple_mean' ? 1.0 : max(0.0, (float) $result->coefficient);
                $weighted += (float) $result->average * $weight;
                $weights += $weight;
            }

            $average = $weights > 0 ? $this->round($weighted / $weights, $policy) : null;
            $counts = $attendance->get($student->id);

            $rows[] = [
                'student_id' => $student->id,
                'average' => $average,
                'days_absent' => (int) ($counts['absent'] ?? 0),
                'days_late' => (int) ($counts['late'] ?? 0),
                'days_excused' => (int) ($counts['excused'] ?? 0),
                'appreciation' => $average !== null ? $this->appreciation($average, $policy->scale_id) : null,
                'needs_support' => $average !== null && $average < (float) ($policy->support_threshold ?? $policy->pass_mark),
            ];
        }

        $averages = collect($rows)->pluck('average')->filter(fn ($v) => $v !== null);
        $classAverage = $averages->count() ? $this->round($averages->avg(), $policy) : null;
        $ranks = $policy->ranking_enabled ? $this->ranks($rows) : [];

        $saved = [];
        foreach ($rows as $row) {
            $saved[] = TermResult::updateOrCreate(
                ['student_id' => $row['student_id'], 'term_id' => $termId],
                [
                    'class_id' => $classId,
                    'average' => $row['average'],
                    'class_average' => $classAverage,
                    'rank' => $ranks[$row['student_id']] ?? null,
                    'class_size' => count($rows),
                    'days_absent' => $row['days_absent'],
                    'days_late' => $row['days_late'],
                    'days_excused' => $row['days_excused'],
                    'appreciation' => $row['appreciation'],
                    'needs_support' => $row['needs_support'],
                    'policy_id' => $policy->id,
                ]
            );
        }

        return $saved;
    }

    /** @return array<int, AnnualResult> */
    public function computeAnnual(int $classId, int $academicYearId): array
    {
        $class = Classroom::with('level')->findOrFail($classId);
        $policy = $this->policyFor($academicYearId, $class->level_id);
        $terms = Term::where('academic_year_id', $academicYearId)->orderBy('sequence')->get();

        $termResults = TermResult::where('class_id', $classId)
            ->whereIn('term_id', $terms->pluck('id'))->get();

        $weights = $policy->term_weights ?: [];
        $students = $this->studentsInClass($classId);
        $rows = [];

        foreach ($students as $student) {
            $results = $termResults->where('student_id', $student->id);

            $average = match ($policy->term_aggregation) {
                'simple_mean' => $this->meanOf($results->pluck('average')->filter(fn ($v) => $v !== null)),
                'last_term_only' => (float) ($results->sortByDesc(fn ($r) => $r->term_id)->first()?->average ?? 0) ?: null,
                default => $this->weightedTerms($results, $terms, $weights),
            };

            $rows[] = [
                'student_id' => $student->id,
                'average' => $average !== null ? $this->round($average, $policy) : null,
            ];
        }

        $ranks = $policy->ranking_enabled ? $this->ranks($rows) : [];
        $saved = [];

        foreach ($rows as $row) {
            $saved[] = AnnualResult::updateOrCreate(
                ['student_id' => $row['student_id'], 'academic_year_id' => $academicYearId],
                [
                    'class_id' => $classId,
                    'average' => $row['average'],
                    'rank' => $ranks[$row['student_id']] ?? null,
                    'policy_id' => $policy->id,
                ]
            );
        }

        return $saved;
    }

    /**
     * Metrics a promotion rule may reference. Values are read from snapshots,
     * never from live formulas, so a recommendation is explainable later.
     */
    public function metricsFor(Student $student, int $classId, int $academicYearId): array
    {
        $class = Classroom::with('level')->findOrFail($classId);
        $policy = $this->policyFor($academicYearId, $class->level_id);
        $terms = Term::where('academic_year_id', $academicYearId)->orderBy('sequence')->get();

        $subjectResults = SubjectTermResult::where('class_id', $classId)
            ->where('student_id', $student->id)
            ->whereIn('term_id', $terms->pluck('id'))
            ->get();

        $rules = GradingPolicySubjectRule::where('policy_id', $policy->id)->get()->keyBy('subject_id');
        $weights = $policy->term_weights ?: [];

        $subjectAnnual = [];
        foreach ($subjectResults->groupBy('subject_id') as $subjectId => $rows) {
            $weighted = 0.0;
            $total = 0.0;
            foreach ($rows as $row) {
                if ($row->average === null) {
                    continue;
                }
                $w = (float) ($weights[$row->term_id] ?? $terms->firstWhere('id', $row->term_id)?->weight ?? 1);
                $weighted += (float) $row->average * $w;
                $total += $w;
            }
            if ($total > 0) {
                $subjectAnnual[$subjectId] = $weighted / $total;
            }
        }

        $passMark = fn (int $subjectId) => (float) ($rules->get($subjectId)?->pass_mark ?? $policy->pass_mark);
        $elimMark = fn (int $subjectId) => ($rules->get($subjectId)?->eliminatory_mark ?? $policy->eliminatory_mark);

        $belowPass = 0;
        $belowElim = 0;
        $coreTotal = 0.0;
        $coreCount = 0;
        $belowPassCore = 0;
        $belowElimCore = 0;

        foreach ($subjectAnnual as $subjectId => $average) {
            $isCore = (bool) ($rules->get($subjectId)?->is_core ?? false);
            $elim = $elimMark((int) $subjectId);

            if ($average < $passMark((int) $subjectId)) {
                $belowPass++;
                if ($isCore) {
                    $belowPassCore++;
                }
            }
            if ($elim !== null && $average < (float) $elim) {
                $belowElim++;
                if ($isCore) {
                    $belowElimCore++;
                }
            }
            if ($isCore) {
                $coreTotal += $average;
                $coreCount++;
            }
        }

        $annual = AnnualResult::where('student_id', $student->id)
            ->where('academic_year_id', $academicYearId)->first();

        $termAverages = TermResult::where('student_id', $student->id)
            ->whereIn('term_id', $terms->pluck('id'))->get()
            ->mapWithKeys(fn ($r) => [$terms->firstWhere('id', $r->term_id)?->sequence => (float) $r->average]);

        $previous = AnnualResult::query()
            ->where('student_id', $student->id)
            ->where('academic_year_id', '<', $academicYearId)
            ->orderByDesc('academic_year_id')->value('decision');

        $maxSort = (int) Level::max('sort_order');

        return [
            'annual_average' => (float) ($annual->average ?? 0),
            'term_average' => fn (array $params) => (float) ($termAverages[$params['term_sequence'] ?? 1] ?? 0),
            'subjects_below_pass_mark' => $belowPass,
            'subjects_below_eliminatory' => $belowElim,
            'subjects_below_pass_mark_core' => $belowPassCore,
            'subjects_below_eliminatory_core' => $belowElimCore,
            'core_subjects_average' => $coreCount ? $coreTotal / $coreCount : 0.0,
            'unexcused_absence_days' => $this->attendanceCounts($classId, null, $student->id)['absent'] ?? 0,
            'previous_year_decision' => $previous ?? 'none',
            'is_final_level' => (int) $class->level?->sort_order >= $maxSort,
            'student_id' => $student->id,
            'class_id' => $classId,
            'academic_year_id' => $academicYearId,
        ];
    }

    public function round(float $value, GradingPolicy $policy): float
    {
        $factor = 10 ** (int) $policy->rounding_decimals;

        return match ($policy->rounding_mode) {
            'down' => floor($value * $factor) / $factor,
            'up' => ceil($value * $factor) / $factor,
            default => round($value, (int) $policy->rounding_decimals),
        };
    }

    private function aggregate(array $values, GradingPolicy $policy): float
    {
        $method = $policy->averaging_method;
        $params = $policy->averaging_params ?? [];

        if ($method === 'best_n' || $method === 'drop_lowest') {
            $n = max(1, (int) ($params['n'] ?? 1));
            usort($values, fn ($a, $b) => $b['value'] <=> $a['value']);
            $values = $method === 'best_n'
                ? array_slice($values, 0, $n)
                : array_slice($values, $n);
        }

        if (! $values) {
            return 0.0;
        }

        if ($method === 'simple_mean') {
            return array_sum(array_column($values, 'value')) / count($values);
        }

        $weighted = 0.0;
        $weights = 0.0;
        foreach ($values as $row) {
            $weighted += $row['value'] * $row['weight'];
            $weights += $row['weight'];
        }

        return $weights > 0 ? $weighted / $weights : 0.0;
    }

    /** Competition ranking: equal averages share a rank. */
    private function ranks(array $rows): array
    {
        $scored = collect($rows)->filter(fn ($r) => $r['average'] !== null)
            ->sortByDesc('average')->values();

        $ranks = [];
        $rank = 1;
        $previous = null;
        foreach ($scored as $index => $row) {
            if ($previous !== null && (float) $row['average'] !== (float) $previous) {
                $rank = $index + 1;
            }
            $ranks[$row['student_id']] = $rank;
            $previous = $row['average'];
        }

        return $ranks;
    }

    private function appreciation(float $average, ?int $scaleId): ?string
    {
        return GradingScaleBand::where('scale_id', $scaleId)
            ->where('min_score', '<=', $average)
            ->where('max_score', '>=', $average)
            ->value('label');
    }

    private function weightedTerms(Collection $results, Collection $terms, array $weights): ?float
    {
        $weighted = 0.0;
        $total = 0.0;

        foreach ($results as $result) {
            if ($result->average === null) {
                continue;
            }
            $term = $terms->firstWhere('id', $result->term_id);
            $weight = (float) ($weights[$result->term_id] ?? $weights[(string) $result->term_id] ?? $term?->weight ?? 1);
            $weighted += (float) $result->average * $weight;
            $total += $weight;
        }

        return $total > 0 ? $weighted / $total : null;
    }

    private function meanOf(Collection $values): ?float
    {
        $values = $values->filter(fn ($v) => $v !== null);

        return $values->count() ? (float) $values->avg() : null;
    }

    private function studentsInClass(int $classId): Collection
    {
        return Student::query()
            ->join('enrollments', 'enrollments.student_id', '=', 'students.id')
            ->where('enrollments.class_id', $classId)
            ->whereNull('enrollments.end_date')
            ->whereIn('enrollments.status', ['active', 'completed'])
            ->whereNull('students.deleted_at')
            ->orderBy('students.last_name')
            ->orderBy('students.first_name')
            ->select('students.*')
            ->get();
    }

    /**
     * Distinct days per attendance status, used in term/annual snapshots.
     */
    private function attendanceCounts(int $classId, ?Term $term, ?int $studentId = null): Collection
    {
        $query = DB::table('attendance_records as r')
            ->join('attendance_sessions as s', 's.id', '=', 'r.session_id')
            ->where('s.class_id', $classId)
            ->when($term, fn ($q) => $q->whereBetween('s.session_date', [$term->start_date, $term->end_date]))
            ->when($studentId, fn ($q) => $q->where('r.student_id', $studentId))
            ->selectRaw('r.student_id, r.status, count(distinct s.session_date) as days')
            ->groupBy('r.student_id', 'r.status');

        return $query->get()->groupBy('student_id')->map(
            fn (Collection $rows) => $rows->pluck('days', 'status')->map(fn ($v) => (int) $v)->all()
        );
    }
}
