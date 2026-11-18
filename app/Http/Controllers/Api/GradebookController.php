<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Assessment;
use App\Models\Classroom;
use App\Models\ClassSubjectTeacher;
use App\Models\Enrollment;
use App\Models\Grade;
use App\Models\GradeHistory;
use App\Models\GradeValidationEvent;
use App\Models\Student;
use App\Models\Term;
use App\Services\GradeCalculator;
use App\Services\PolicyService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class GradebookController extends Controller
{
    public function __construct(
        private readonly GradeCalculator $calculator,
        private readonly PolicyService $policies,
    ) {}

    public function index(Request $request)
    {
        return Assessment::with(['type', 'term', 'classSubjectTeacher.subject', 'classSubjectTeacher.classroom.level'])
            ->withCount('grades')
            ->when($request->filled('class_id'), fn ($q) => $q->whereHas('classSubjectTeacher', fn ($w) => $w->where('class_id', $request->integer('class_id'))))
            ->when($request->filled('subject_id'), fn ($q) => $q->whereHas('classSubjectTeacher', fn ($w) => $w->where('subject_id', $request->integer('subject_id'))))
            ->when($request->filled('term_id'), fn ($q) => $q->where('term_id', $request->integer('term_id')))
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->input('status')))
            ->orderByDesc('assessment_date')->orderByDesc('id')
            ->get();
    }

    public function store(Request $request)
    {
        $data = $this->validateAssessment($request);
        $this->assertCanTeach($request, (int) $data['class_subject_teacher_id']);

        $assessment = Assessment::create($data + [
            'status' => 'open',
            'created_by' => $request->user()->id,
        ]);

        activity('grading')->performedOn($assessment)->log('assessment.created');

        return response()->json($assessment->load('type', 'term'), 201);
    }

    public function update(Request $request, Assessment $assessment)
    {
        $this->assertEditable($assessment);
        $this->assertCanTeach($request, (int) $assessment->class_subject_teacher_id);
        $assessment->update($this->validateAssessment($request));

        return $assessment->fresh();
    }

    public function destroy(Assessment $assessment)
    {
        $this->assertEditable($assessment);
        $assessment->delete();

        return response()->noContent();
    }

    /** Grade sheet: roster with existing grades. */
    public function show(Assessment $assessment)
    {
        $assessment->load(['type', 'term', 'classSubjectTeacher.subject', 'classSubjectTeacher.classroom.level']);
        $cst = $assessment->classSubjectTeacher;

        $students = Enrollment::with('student')
            ->where('class_id', $cst->class_id)
            ->whereNull('end_date')->get()->pluck('student')->filter()
            ->sortBy('last_name')->values();

        $grades = Grade::where('assessment_id', $assessment->id)->get()->keyBy('student_id');

        return [
            'assessment' => $assessment,
            'students' => $students,
            'grades' => $grades,
            'locked' => $assessment->status === 'validated',
        ];
    }

    /**
     * Bulk grade entry. Every change is written to grade_history, and a
     * validated assessment cannot be edited without the reopen workflow.
     */
    public function saveGrades(Request $request, Assessment $assessment)
    {
        $this->assertEditable($assessment);
        $this->assertCanTeach($request, (int) $assessment->class_subject_teacher_id);

        $data = $request->validate([
            'reason' => 'nullable|string|max:255',
            'grades' => 'required|array|min:1',
            'grades.*.student_id' => 'required|integer|exists:students,id',
            'grades.*.status' => 'required|in:graded,absent,excused,not_graded',
            'grades.*.score' => 'nullable|numeric|min:0',
            'grades.*.comment' => 'nullable|string|max:255',
        ]);

        $max = (float) $assessment->max_score;

        DB::transaction(function () use ($data, $assessment, $max, $request) {
            foreach ($data['grades'] as $row) {
                if ($row['status'] === 'graded' && ($row['score'] === null || $row['score'] === '')) {
                    throw ValidationException::withMessages(['grades' => 'A graded row needs a score.']);
                }
                if ($row['status'] === 'graded' && (float) $row['score'] > $max) {
                    throw ValidationException::withMessages(['grades' => "Score {$row['score']} exceeds the assessment maximum of {$max}."]);
                }

                $grade = Grade::firstOrNew([
                    'assessment_id' => $assessment->id,
                    'student_id' => $row['student_id'],
                ]);

                $score = $row['status'] === 'graded' ? $row['score'] : null;
                $old = $grade->exists ? ['score' => $grade->score, 'status' => $grade->status] : null;

                $grade->fill([
                    'score' => $score,
                    'status' => $row['status'],
                    'comment' => $row['comment'] ?? null,
                    'entered_by' => $request->user()->id,
                ])->save();

                if ($old && ((string) $old['score'] !== (string) $grade->score || $old['status'] !== $grade->status)) {
                    GradeHistory::create([
                        'grade_id' => $grade->id,
                        'old_score' => $old['score'],
                        'new_score' => $grade->score,
                        'old_status' => $old['status'],
                        'new_status' => $grade->status,
                        'changed_by' => $request->user()->id,
                        'reason' => $data['reason'] ?? null,
                        'changed_at' => now(),
                    ]);
                }
            }
        });

        return ['saved' => count($data['grades'])];
    }

    /** Submit / validate / reject / reopen a class-subject-term, fully logged. */
    public function workflow(Request $request)
    {
        $data = $request->validate([
            'class_id' => 'required|integer|exists:classes,id',
            'subject_id' => 'required|integer|exists:subjects,id',
            'term_id' => 'required|integer|exists:terms,id',
            'action' => 'required|in:submit,validate,reject,reopen',
            'reason' => 'required_if:action,reject,reopen|nullable|string|max:255',
        ]);

        $assessments = Assessment::query()
            ->whereHas('classSubjectTeacher', fn ($q) => $q->where('class_id', $data['class_id'])->where('subject_id', $data['subject_id']))
            ->where('term_id', $data['term_id'])
            ->get();

        if ($assessments->isEmpty()) {
            throw ValidationException::withMessages(['assessments' => 'No assessments exist for this class, subject and term.']);
        }

        DB::transaction(function () use ($data, $assessments, $request) {
            GradeValidationEvent::create([
                'class_id' => $data['class_id'],
                'subject_id' => $data['subject_id'],
                'term_id' => $data['term_id'],
                'action' => $data['action'],
                'performed_by' => $request->user()->id,
                'reason' => $data['reason'] ?? null,
                'created_at' => now(),
            ]);

            foreach ($assessments as $assessment) {
                $status = match ($data['action']) {
                    'submit' => 'submitted',
                    'validate' => 'validated',
                    'reject', 'reopen' => 'open',
                };
                $assessment->update(['status' => $status]);
            }

            if ($data['action'] === 'validate') {
                $this->calculator->computeSubjectTerm((int) $data['class_id'], (int) $data['subject_id'], (int) $data['term_id'], true);
                $class = Classroom::findOrFail($data['class_id']);
                $policy = $this->calculator->policyFor($class->academic_year_id, $class->level_id);
                $this->policies->lock($policy, 'Results validated for class #'.$class->id);
                $this->calculator->computeTerm((int) $data['class_id'], (int) $data['term_id']);
            }
        });

        return ['status' => $data['action'] === 'validate' ? 'validated' : 'open'];
    }

    public function events(Request $request)
    {
        return GradeValidationEvent::with('performer')
            ->when($request->filled('class_id'), fn ($q) => $q->where('class_id', $request->integer('class_id')))
            ->when($request->filled('term_id'), fn ($q) => $q->where('term_id', $request->integer('term_id')))
            ->orderByDesc('created_at')->limit(100)->get();
    }

    /** Reporting: students with no grade on an open assessment. */
    public function missing(Request $request)
    {
        $rows = DB::table('assessments as a')
            ->join('class_subject_teachers as cst', 'cst.id', '=', 'a.class_subject_teacher_id')
            ->join('enrollments as e', function ($join) {
                $join->on('e.class_id', '=', 'cst.class_id')->whereNull('e.end_date')->where('e.status', 'active');
            })
            ->leftJoin('grades as g', function ($join) {
                $join->on('g.assessment_id', '=', 'a.id')->on('g.student_id', '=', 'e.student_id');
            })
            ->join('students as s', 's.id', '=', 'e.student_id')
            ->whereNull('a.deleted_at')
            ->whereIn('a.status', ['open', 'submitted'])
            ->where(function ($q) {
                $q->whereNull('g.id')->orWhere('g.status', 'not_graded');
            })
            ->when($request->filled('class_id'), fn ($q) => $q->where('cst.class_id', $request->integer('class_id')))
            ->when($request->filled('term_id'), fn ($q) => $q->where('a.term_id', $request->integer('term_id')))
            ->select('a.id as assessment_id', 'a.title', 'cst.class_id', 'cst.subject_id', 'a.term_id', 'e.student_id', 's.first_name', 's.last_name')
            ->limit(500)->get();

        return $rows;
    }

    /** Reporting: per-assessment statistics. */
    public function stats(Request $request)
    {
        return Assessment::query()
            ->withCount(['grades as graded_count' => fn ($q) => $q->where('status', 'graded')])
            ->when($request->filled('class_id'), fn ($q) => $q->whereHas('classSubjectTeacher', fn ($w) => $w->where('class_id', $request->integer('class_id'))))
            ->when($request->filled('term_id'), fn ($q) => $q->where('term_id', $request->integer('term_id')))
            ->get()
            ->map(function (Assessment $assessment) {
                $rows = Grade::where('assessment_id', $assessment->id)->where('status', 'graded')->pluck('score');

                return [
                    'assessment_id' => $assessment->id,
                    'title' => $assessment->title,
                    'graded_count' => $assessment->graded_count,
                    'average_on_20' => $rows->count() ? round($rows->avg() / max(0.01, (float) $assessment->max_score) * 20, 2) : null,
                    'min_on_20' => $rows->count() ? round($rows->min() / max(0.01, (float) $assessment->max_score) * 20, 2) : null,
                    'max_on_20' => $rows->count() ? round($rows->max() / max(0.01, (float) $assessment->max_score) * 20, 2) : null,
                ];
            });
    }

    private function validateAssessment(Request $request): array
    {
        return $request->validate([
            'class_subject_teacher_id' => 'required|integer|exists:class_subject_teachers,id',
            'term_id' => 'required|integer|exists:terms,id',
            'assessment_type_id' => 'required|integer|exists:assessment_types,id',
            'title' => 'required|string|max:160',
            'assessment_date' => 'nullable|date',
            'max_score' => 'required|numeric|min:0.01',
            'weight' => 'numeric|min:0',
        ]);
    }

    private function assertEditable(Assessment $assessment): void
    {
        if ($assessment->status === 'validated') {
            throw ValidationException::withMessages([
                'assessment' => 'This assessment is validated and locked. Use the reopen workflow with a reason to change it.',
            ]);
        }
    }

    private function assertCanTeach(Request $request, int $assignmentId): void
    {
        $user = $request->user();

        if ($user->can('grading.manage')) {
            return;
        }

        $assignment = ClassSubjectTeacher::find($assignmentId);
        if (! $assignment || (int) $assignment->staff_id !== (int) $user->staff?->id) {
            abort(403, 'You can only enter grades for classes and subjects assigned to you.');
        }
    }
}
