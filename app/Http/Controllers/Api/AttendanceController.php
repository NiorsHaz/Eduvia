<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AbsenceJustification;
use App\Models\AttendanceRecord;
use App\Models\AttendanceSession;
use App\Models\Classroom;
use App\Models\Enrollment;
use App\Services\AttendanceService;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class AttendanceController extends Controller
{
    public function __construct(private readonly AttendanceService $attendance) {}

    public function index(Request $request)
    {
        return AttendanceSession::with(['classroom.level', 'term'])
            ->withCount('records')
            ->when($request->filled('class_id'), fn ($q) => $q->where('class_id', $request->integer('class_id')))
            ->when($request->filled('from'), fn ($q) => $q->whereDate('session_date', '>=', $request->date('from')))
            ->when($request->filled('to'), fn ($q) => $q->whereDate('session_date', '<=', $request->date('to')))
            ->orderByDesc('session_date')->paginate(30);
    }

    public function show(AttendanceSession $session)
    {
        return [
            'session' => $session->load(['classroom.level', 'term']),
            'records' => AttendanceRecord::with('student')
                ->where('session_id', $session->id)->get(),
        ];
    }

    /** The roster plus any already-recorded statuses, for the roll-call screen. */
    public function roster(Request $request)
    {
        $data = $request->validate([
            'class_id' => 'required|integer|exists:classes,id',
            'date' => 'required|date',
            'timetable_entry_id' => 'nullable|integer|exists:timetable_entries,id',
        ]);

        $class = Classroom::with('level')->findOrFail($data['class_id']);
        $students = Enrollment::with('student')->where('class_id', $class->id)
            ->whereNull('end_date')->get()->pluck('student')->filter()
            ->sortBy('last_name')->values();

        $session = AttendanceSession::where('class_id', $class->id)
            ->whereDate('session_date', $data['date'])
            ->where('timetable_entry_id', $data['timetable_entry_id'] ?? null)
            ->first();

        $records = $session
            ? AttendanceRecord::where('session_id', $session->id)->get()->keyBy('student_id')
            : collect();

        return [
            'class' => $class,
            'students' => $students,
            'session' => $session,
            'records' => $records,
            'term' => $class->academicYear?->terms()->whereDate('start_date', '<=', $data['date'])->whereDate('end_date', '>=', $data['date'])->first(),
        ];
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'class_id' => 'required|integer|exists:classes,id',
            'session_date' => 'required|date',
            'term_id' => 'nullable|integer|exists:terms,id',
            'timetable_entry_id' => 'nullable|integer|exists:timetable_entries,id',
            'client_uuid' => 'nullable|uuid',
            'records' => 'required|array|min:1',
            'records.*.student_id' => 'required|integer|exists:students,id',
            'records.*.status' => 'required|in:present,absent,late,excused,left_early',
            'records.*.minutes_late' => 'nullable|integer|min:0',
            'records.*.note' => 'nullable|string|max:255',
        ]);

        $session = $this->attendance->save($data, $data['records'], $request->user()->id);

        return response()->json($session->load('records.student'), 201);
    }

    public function updateRecord(Request $request, AttendanceRecord $record)
    {
        $data = $request->validate([
            'status' => 'required|in:present,absent,late,excused,left_early',
            'minutes_late' => 'nullable|integer|min:0',
            'note' => 'nullable|string|max:255',
            'reason' => 'required|string|max:255',
        ]);

        $old = $record->status;
        $record->update($data);

        \App\Models\AttendanceRecordHistory::create([
            'record_id' => $record->id,
            'old_status' => $old,
            'new_status' => $data['status'],
            'changed_by' => $request->user()->id,
            'reason' => $data['reason'],
            'changed_at' => now(),
        ]);

        return $record;
    }

    public function justifications(Request $request)
    {
        return AbsenceJustification::with(['student'])
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->input('status')))
            ->orderByDesc('created_at')->paginate(25);
    }

    public function storeJustification(Request $request)
    {
        $data = $request->validate([
            'student_id' => 'required|integer|exists:students,id',
            'date_from' => 'required|date',
            'date_to' => 'required|date|after_or_equal:date_from',
            'reason' => 'required|string',
            'attendance_record_id' => 'nullable|integer|exists:attendance_records,id',
        ]);

        $justification = AbsenceJustification::create($data + [
            'submitted_by' => $request->user()->id,
            'status' => 'pending',
            'created_at' => now(),
        ]);

        return response()->json($justification, 201);
    }

    public function reviewJustification(Request $request, AbsenceJustification $justification)
    {
        $data = $request->validate([
            'status' => 'required|in:approved,rejected,more_info_requested',
            'review_note' => 'nullable|string',
        ]);

        if ($justification->status !== 'pending') {
            throw ValidationException::withMessages(['status' => 'This justification has already been reviewed.']);
        }

        $this->attendance->approveJustification($justification, $request->user()->id, $data['status'], $data['review_note'] ?? null);

        return $justification->fresh();
    }
}
