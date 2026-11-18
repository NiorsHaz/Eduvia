<?php

namespace App\Services;

use App\Models\ReportCard;
use App\Models\ReportCardComment;
use App\Models\Classroom;
use App\Models\Student;
use App\Models\Subject;
use App\Models\SubjectTermResult;
use App\Models\Term;
use App\Models\TermResult;
use Illuminate\Validation\ValidationException;

class ReportCardService
{
    public function __construct(private readonly GradeCalculator $calculator) {}

    /**
     * Build a versioned report card from validated snapshots. A correction
     * supersedes the live card and creates version n+1.
     */
    public function build(Student $student, Term $term, ?int $userId = null): ReportCard
    {
        $termResult = TermResult::where('student_id', $student->id)->where('term_id', $term->id)->first();

        if (! $termResult) {
            throw ValidationException::withMessages([
                'results' => 'No computed term results exist for this student. Validate the gradebook and compute results first.',
            ]);
        }

        $enrollment = $student->currentEnrollment;
        if (! $enrollment) {
            throw ValidationException::withMessages(['enrollment' => 'This student is not actively enrolled.']);
        }

        $policy = $this->calculator->policyFor($term->academic_year_id, Classroom::find($termResult->class_id)?->level_id);

        $subjects = SubjectTermResult::with([])
            ->where('student_id', $student->id)
            ->where('term_id', $term->id)
            ->get()
            ->map(function (SubjectTermResult $row) use ($policy) {
                return [
                    'subject_id' => $row->subject_id,
                    'subject' => Subject::find($row->subject_id)?->name,
                    'average' => $row->average !== null ? (float) $row->average : null,
                    'coefficient' => (float) $row->coefficient,
                    'points' => $row->average !== null ? round((float) $row->average * (float) $row->coefficient, 2) : null,
                    'class_average' => $row->class_average !== null ? (float) $row->class_average : null,
                    'rank' => $policy->show_rank_on_report_card ? $row->rank : null,
                    'appreciation' => $row->appreciation,
                ];
            })->values()->all();

        $comments = ReportCardComment::where('student_id', $student->id)
            ->where('term_id', $term->id)
            ->get()
            ->groupBy('author_role')
            ->map(fn ($rows) => $rows->first()->comment);

        $live = ReportCard::where('student_id', $student->id)
            ->where('academic_year_id', $term->academic_year_id)
            ->where('term_id', $term->id)
            ->where('status', '<>', 'superseded')
            ->first();

        $snapshot = [
            'student' => [
                'id' => $student->id,
                'number' => $student->student_number,
                'name' => trim($student->first_name.' '.$student->last_name),
                'date_of_birth' => $student->date_of_birth?->toDateString(),
            ],
            'class' => $enrollment->classroom?->label,
            'level' => $enrollment->classroom?->level?->name,
            'term' => $term->name,
            'academic_year' => $term->academicYear?->name,
            'subjects' => $subjects,
            'term_result' => [
                'average' => $termResult->average !== null ? (float) $termResult->average : null,
                'class_average' => $termResult->class_average !== null ? (float) $termResult->class_average : null,
                'rank' => $policy->show_rank_on_report_card ? $termResult->rank : null,
                'class_size' => $termResult->class_size,
                'days_absent' => $termResult->days_absent,
                'days_late' => $termResult->days_late,
                'days_excused' => $termResult->days_excused,
                'appreciation' => $termResult->appreciation,
                'needs_support' => (bool) $termResult->needs_support,
            ],
            'comments' => [
                'teacher' => $comments->get('teacher'),
                'class_supervisor' => $comments->get('class_supervisor'),
                'academic_director' => $comments->get('academic_director'),
                'director' => $comments->get('director'),
            ],
            'policy_id' => $termResult->policy_id,
            'generated_at' => now()->toIso8601String(),
        ];

        if ($live && in_array($live->status, ['validated', 'published'], true)) {
            $live->update(['status' => 'superseded']);
            $version = (int) $live->version + 1;
        } else {
            $version = $live?->version ?? 1;
        }

        if ($live && $live->status === 'draft') {
            $live->update(['snapshot' => $snapshot, 'generated_by' => $userId, 'generated_at' => now()]);

            return $live->fresh();
        }

        return ReportCard::create([
            'student_id' => $student->id,
            'class_id' => $termResult->class_id,
            'academic_year_id' => $term->academic_year_id,
            'term_id' => $term->id,
            'version' => $version,
            'status' => 'draft',
            'snapshot' => $snapshot,
            'generated_by' => $userId,
            'generated_at' => now(),
            'created_at' => now(),
        ]);
    }

    public function publish(ReportCard $card, int $userId): ReportCard
    {
        $card->update([
            'status' => 'published',
            'validated_by' => $userId,
            'validated_at' => now(),
            'published_at' => now(),
        ]);

        return $card;
    }
}
