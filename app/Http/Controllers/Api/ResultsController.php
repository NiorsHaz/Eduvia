<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AnnualResult;
use App\Models\Classroom;
use App\Models\ClassSubjectTeacher;
use App\Models\Enrollment;
use App\Models\PromotionRule;
use App\Models\ReportCard;
use App\Models\Student;
use App\Models\SubjectTermResult;
use App\Models\Term;
use App\Models\TermResult;
use App\Services\GradeCalculator;
use App\Services\PolicyService;
use App\Services\PromotionEvaluator;
use App\Services\ReportCardService;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class ResultsController extends Controller
{
    public function __construct(
        private readonly GradeCalculator $calculator,
        private readonly PromotionEvaluator $promotions,
        private readonly ReportCardService $reportCards,
        private readonly PolicyService $policies,
    ) {}

    /** Compute and snapshot every subject then the term average for a class. */
    public function computeTerm(Request $request)
    {
        $data = $request->validate([
            'class_id' => 'required|integer|exists:classes,id',
            'term_id' => 'required|integer|exists:terms,id',
        ]);

        $class = Classroom::findOrFail($data['class_id']);
        $subjectIds = ClassSubjectTeacher::where('class_id', $class->id)->pluck('subject_id')->unique();

        if ($subjectIds->isEmpty()) {
            throw ValidationException::withMessages(['class_id' => 'No subjects are assigned to this class yet.']);
        }

        foreach ($subjectIds as $subjectId) {
            $this->calculator->computeSubjectTerm($class->id, (int) $subjectId, (int) $data['term_id'], false);
        }

        $results = $this->calculator->computeTerm($class->id, (int) $data['term_id']);

        $class->academicYear->terms()->where('id', $data['term_id'])->first()
            ? $this->policies->lock(
                $this->calculator->policyFor($class->academic_year_id, $class->level_id),
                'Term results computed for class #'.$class->id
            )
            : null;

        return ['computed' => count($results), 'subjects' => $subjectIds->count()];
    }

    public function termResults(Request $request)
    {
        $data = $request->validate([
            'class_id' => 'required|integer|exists:classes,id',
            'term_id' => 'required|integer|exists:terms,id',
        ]);

        $className = Classroom::with('level')->find($data['class_id'])?->label;

        $rows = TermResult::with('student')
            ->where('class_id', $data['class_id'])->where('term_id', $data['term_id'])
            ->get()
            ->sortByDesc('average')->values()
            ->map(fn (TermResult $row, $index) => [
                'student_id' => $row->student_id,
                'student' => trim(($row->student?->first_name ?? '').' '.($row->student?->last_name ?? '')),
                'student_number' => $row->student?->student_number,
                'average' => $row->average !== null ? (float) $row->average : null,
                'rank' => $row->rank,
                'class_average' => $row->class_average !== null ? (float) $row->class_average : null,
                'days_absent' => $row->days_absent,
                'needs_support' => (bool) $row->needs_support,
            ]);

        return ['class' => $className, 'results' => $rows];
    }

    public function subjectResults(Request $request)
    {
        $data = $request->validate([
            'class_id' => 'required|integer|exists:classes,id',
            'term_id' => 'required|integer|exists:terms,id',
        ]);

        return SubjectTermResult::with('student')
            ->where('class_id', $data['class_id'])->where('term_id', $data['term_id'])
            ->get();
    }

    public function computeAnnual(Request $request)
    {
        $data = $request->validate([
            'class_id' => 'required|integer|exists:classes,id',
            'academic_year_id' => 'required|integer|exists:academic_years,id',
        ]);

        $results = $this->calculator->computeAnnual((int) $data['class_id'], (int) $data['academic_year_id']);

        return ['computed' => count($results)];
    }

    public function annual(Request $request)
    {
        $data = $request->validate([
            'class_id' => 'required|integer|exists:classes,id',
            'academic_year_id' => 'required|integer|exists:academic_years,id',
        ]);

        return AnnualResult::with('student')
            ->where('class_id', $data['class_id'])->where('academic_year_id', $data['academic_year_id'])
            ->get()->sortByDesc('average')->values();
    }

    /** Suggest decisions with the configured rule set (never silently final). */
    public function promotePreview(Request $request)
    {
        $class = Classroom::findOrFail($request->validate([
            'class_id' => 'required|integer|exists:classes,id',
        ])['class_id']);

        return ['suggestions' => $this->promotions->applyToClass($class)];
    }

    public function confirmDecision(Request $request, AnnualResult $annualResult)
    {
        $data = $request->validate([
            'decision' => 'required|in:promoted,repeat,conditional,graduated,left,council_review',
            'note' => 'nullable|string|max:255',
        ]);

        $this->promotions->confirm($annualResult, $data['decision'], $request->user()->id, $data['note'] ?? null);

        return $annualResult->fresh();
    }

    public function reportCards(Request $request)
    {
        return ReportCard::with(['student', 'classroom.level', 'term'])
            ->when($request->filled('class_id'), fn ($q) => $q->where('class_id', $request->integer('class_id')))
            ->when($request->filled('term_id'), fn ($q) => $q->where('term_id', $request->integer('term_id')))
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->input('status')))
            ->orderByDesc('id')->paginate(50);
    }

    public function buildReportCard(Request $request, Student $student)
    {
        $data = $request->validate(['term_id' => 'required|integer|exists:terms,id']);
        $term = Term::with('academicYear')->findOrFail($data['term_id']);

        $card = $this->reportCards->build($student, $term, $request->user()->id);

        return response()->json($card, 201);
    }

    public function buildClassReportCards(Request $request)
    {
        $data = $request->validate([
            'class_id' => 'required|integer|exists:classes,id',
            'term_id' => 'required|integer|exists:terms,id',
        ]);

        $term = Term::with('academicYear')->findOrFail($data['term_id']);
        $students = Enrollment::with('student')->where('class_id', $data['class_id'])
            ->whereNull('end_date')->get()->pluck('student')->filter();

        $built = 0;
        $errors = [];

        foreach ($students as $student) {
            try {
                $this->reportCards->build($student, $term, $request->user()->id);
                $built++;
            } catch (ValidationException $e) {
                $errors[] = ['student_id' => $student->id, 'message' => $e->getMessage()];
            }
        }

        return ['built' => $built, 'errors' => $errors];
    }

    public function publishReportCard(Request $request, ReportCard $reportCard)
    {
        return $this->reportCards->publish($reportCard, $request->user()->id);
    }

    public function showReportCard(ReportCard $reportCard)
    {
        return $reportCard->load(['student', 'classroom.level', 'term']);
    }

    public function rules(Request $request)
    {
        $data = $request->validate([
            'class_id' => 'required|integer|exists:classes,id',
        ]);

        $class = Classroom::findOrFail($data['class_id']);
        $policy = $this->calculator->policyFor($class->academic_year_id, $class->level_id);

        return [
            'policy' => $policy->load('scale', 'subjectRules'),
            'rules' => $this->promotions->rulesFor($policy, $class->level_id),
        ];
    }

    public function support(Request $request)
    {
        return TermResult::with('student')
            ->where('needs_support', true)
            ->when($request->filled('term_id'), fn ($q) => $q->where('term_id', $request->integer('term_id')))
            ->when($request->filled('class_id'), fn ($q) => $q->where('class_id', $request->integer('class_id')))
            ->orderBy('average')->limit(200)->get();
    }
}
