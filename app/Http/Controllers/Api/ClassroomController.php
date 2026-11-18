<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Classroom;
use App\Models\ClassSubjectTeacher;
use App\Models\Enrollment;
use App\Models\LevelSubject;
use App\Models\Subject;
use App\Models\TimetableEntry;
use Illuminate\Http\Request;

class ClassroomController extends Controller
{
    public function index(Request $request)
    {
        return Classroom::with(['level', 'track', 'supervisor'])
            ->withCount(['enrollments as students_count' => fn ($q) => $q->whereNull('end_date')])
            ->when($request->filled('academic_year_id'), fn ($q) => $q->where('academic_year_id', $request->integer('academic_year_id')))
            ->when($request->filled('level_id'), fn ($q) => $q->where('level_id', $request->integer('level_id')))
            ->when($request->filled('q'), fn ($q) => $q->where('name', 'like', '%'.$request->string('q').'%'))
            ->orderBy('level_id')->orderBy('name')
            ->get();
    }

    public function show(Classroom $classroom)
    {
        $classroom->load(['level', 'track', 'supervisor', 'homeRoom']);

        $students = $classroom->students()->with('guardians')->orderBy('last_name')->get();

        $subjects = ClassSubjectTeacher::with(['subject', 'staff'])
            ->where('class_id', $classroom->id)
            ->get()
            ->map(fn ($row) => [
                'id' => $row->id,
                'subject_id' => $row->subject_id,
                'subject' => $row->subject?->name,
                'subject_code' => $row->subject?->code,
                'staff_id' => $row->staff_id,
                'teacher' => $row->staff?->full_name,
                'coefficient' => LevelSubject::where('academic_year_id', $classroom->academic_year_id)
                    ->where('level_id', $classroom->level_id)
                    ->where('subject_id', $row->subject_id)
                    ->value('coefficient') ?? 1,
            ]);

        return [
            'class' => $classroom,
            'students' => $students,
            'subjects' => $subjects,
            'timetable' => TimetableEntry::with(['subject', 'staff', 'room'])
                ->where('class_id', $classroom->id)->orderBy('day_of_week')->orderBy('start_time')->get(),
        ];
    }

    public function roster(Classroom $classroom)
    {
        return Enrollment::with('student')
            ->where('class_id', $classroom->id)
            ->whereNull('end_date')
            ->get()
            ->pluck('student')
            ->filter()
            ->sortBy('last_name')
            ->values();
    }

    public function assignSubject(Request $request, Classroom $classroom)
    {
        $data = $request->validate([
            'subject_id' => 'required|integer|exists:subjects,id',
            'staff_id' => 'required|integer|exists:staff,id',
            'starts_on' => 'nullable|date',
            'ends_on' => 'nullable|date|after_or_equal:starts_on',
        ]);

        $row = ClassSubjectTeacher::updateOrCreate(
            ['class_id' => $classroom->id, 'subject_id' => $data['subject_id'], 'staff_id' => $data['staff_id']],
            $data
        );

        return response()->json($row, 201);
    }

    public function unassignSubject(ClassSubjectTeacher $assignment)
    {
        $assignment->delete();

        return response()->noContent();
    }
}
