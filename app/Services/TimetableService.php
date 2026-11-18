<?php

namespace App\Services;

use App\Models\ExamSchedule;
use App\Models\TimetableEntry;
use Illuminate\Validation\ValidationException;

/**
 * Overlap checks that the PostgreSQL drafts enforce with exclusion constraints.
 * SQLite cannot express those, so the same rules are checked before saving.
 */
class TimetableService
{
    public function assertNoConflicts(array $data, ?int $ignoreId = null): void
    {
        $query = TimetableEntry::query()
            ->where('day_of_week', $data['day_of_week'])
            ->where('start_time', '<', $data['end_time'])
            ->where('end_time', '>', $data['start_time'])
            ->when($ignoreId, fn ($q) => $q->whereKeyNot($ignoreId));

        foreach (['staff_id' => 'teacher', 'class_id' => 'class', 'room_id' => 'room'] as $field => $label) {
            if (empty($data[$field])) {
                continue;
            }

            $clash = (clone $query)->where($field, $data[$field])->exists();
            if ($clash) {
                throw ValidationException::withMessages([
                    $field => "This {$label} is already booked during that time slot.",
                ]);
            }
        }
    }

    public function assertExamSlotFree(array $data, ?int $ignoreId = null): void
    {
        $query = ExamSchedule::query()
            ->where('exam_date', $data['exam_date'])
            ->where('start_time', '<', $data['end_time'])
            ->where('end_time', '>', $data['start_time'])
            ->when($ignoreId, fn ($q) => $q->whereKeyNot($ignoreId));

        foreach (['class_id' => 'class', 'room_id' => 'room'] as $field => $label) {
            if (empty($data[$field])) {
                continue;
            }
            if ((clone $query)->where($field, $data[$field])->exists()) {
                throw ValidationException::withMessages([$field => "This {$label} already has an exam paper at that time."]);
            }
        }
    }
}
