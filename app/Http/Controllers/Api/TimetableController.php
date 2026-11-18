<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\TimetableEntry;
use App\Models\TimetableException;
use App\Services\TimetableService;
use Illuminate\Http\Request;

class TimetableController extends Controller
{
    public function __construct(private readonly TimetableService $timetable) {}

    public function index(Request $request)
    {
        return TimetableEntry::with(['subject', 'staff', 'room', 'classroom.level'])
            ->when($request->filled('class_id'), fn ($q) => $q->where('class_id', $request->integer('class_id')))
            ->when($request->filled('staff_id'), fn ($q) => $q->where('staff_id', $request->integer('staff_id')))
            ->when($request->filled('academic_year_id'), fn ($q) => $q->where('academic_year_id', $request->integer('academic_year_id')))
            ->orderBy('day_of_week')->orderBy('start_time')
            ->get();
    }

    public function store(Request $request)
    {
        $data = $this->validateEntry($request);
        $this->timetable->assertNoConflicts($data);

        $entry = TimetableEntry::create($data);
        activity('timetable')->performedOn($entry)->log('timetable.created');

        return response()->json($entry->load(['subject', 'staff', 'room']), 201);
    }

    public function update(Request $request, TimetableEntry $entry)
    {
        $data = $this->validateEntry($request);
        $this->timetable->assertNoConflicts($data, $entry->id);

        $entry->update($data);

        return $entry->fresh(['subject', 'staff', 'room']);
    }

    public function destroy(TimetableEntry $entry)
    {
        $entry->delete();

        return response()->noContent();
    }

    public function exceptions(TimetableEntry $entry)
    {
        return $entry->hasMany(TimetableException::class)->with(['substituteStaff', 'substituteRoom'])->get();
    }

    public function storeException(Request $request, TimetableEntry $entry)
    {
        $data = $request->validate([
            'exception_date' => 'required|date',
            'type' => 'required|in:cancelled,substitute,room_change',
            'substitute_staff_id' => 'required_if:type,substitute|nullable|integer|exists:staff,id',
            'substitute_room_id' => 'required_if:type,room_change|nullable|integer|exists:rooms,id',
            'reason' => 'nullable|string',
        ]);

        $exception = TimetableException::updateOrCreate(
            ['timetable_entry_id' => $entry->id, 'exception_date' => $data['exception_date']],
            $data + ['created_by' => $request->user()->id, 'created_at' => now()]
        );

        activity('timetable')->performedOn($entry)->withProperties($data)->log('timetable.exception');

        return response()->json($exception, 201);
    }

    private function validateEntry(Request $request): array
    {
        return $request->validate([
            'academic_year_id' => 'required|integer|exists:academic_years,id',
            'class_id' => 'required|integer|exists:classes,id',
            'subject_id' => 'required|integer|exists:subjects,id',
            'staff_id' => 'required|integer|exists:staff,id',
            'room_id' => 'nullable|integer|exists:rooms,id',
            'day_of_week' => 'required|integer|between:1,7',
            'start_time' => 'required|date_format:H:i',
            'end_time' => 'required|date_format:H:i|after:start_time',
            'valid_from' => 'required|date',
            'valid_to' => 'nullable|date|after_or_equal:valid_from',
        ]);
    }
}
