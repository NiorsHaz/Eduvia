<?php

namespace App\Services;

use App\Models\AttendanceRecord;
use App\Models\AttendanceRecordHistory;
use App\Models\AttendanceSession;
use Illuminate\Support\Facades\DB;

class AttendanceService
{
    public const STATUSES = ['present', 'absent', 'late', 'excused', 'left_early'];

    /**
     * Save a roll call. The client UUID makes retries from offline devices safe:
     * a repeated upload returns the original session instead of duplicating it.
     *
     * @param  array<int, array{student_id:int,status:string,minutes_late?:int,note?:string}>  $records
     */
    public function save(array $data, array $records, int $userId): AttendanceSession
    {
        return DB::transaction(function () use ($data, $records, $userId) {
            $session = null;

            if (! empty($data['client_uuid'])) {
                $session = AttendanceSession::where('client_uuid', $data['client_uuid'])->first();
                if ($session) {
                    return $session;
                }
            }

            $session = AttendanceSession::firstOrCreate(
                [
                    'class_id' => $data['class_id'],
                    'session_date' => $data['session_date'],
                    'timetable_entry_id' => $data['timetable_entry_id'] ?? null,
                ],
                [
                    'term_id' => $data['term_id'] ?? null,
                    'taken_by' => $userId,
                    'client_uuid' => $data['client_uuid'] ?? null,
                    'submitted_at' => now(),
                ]
            );

            foreach ($records as $row) {
                $record = AttendanceRecord::firstOrNew([
                    'session_id' => $session->id,
                    'student_id' => $row['student_id'],
                ]);

                $oldStatus = $record->exists ? $record->status : null;
                $record->fill([
                    'status' => $row['status'],
                    'minutes_late' => $row['minutes_late'] ?? null,
                    'note' => $row['note'] ?? null,
                    'marked_by' => $userId,
                ])->save();

                if ($oldStatus !== $row['status']) {
                    AttendanceRecordHistory::create([
                        'record_id' => $record->id,
                        'old_status' => $oldStatus,
                        'new_status' => $row['status'],
                        'changed_by' => $userId,
                        'reason' => $row['note'] ?? null,
                        'changed_at' => now(),
                    ]);
                }
            }

            $session->update(['submitted_at' => now()]);

            return $session;
        });
    }

    public function approveJustification(\App\Models\AbsenceJustification $justification, int $reviewerId, string $decision, ?string $note = null): void
    {
        DB::transaction(function () use ($justification, $reviewerId, $decision, $note) {
            $justification->update([
                'status' => $decision,
                'reviewed_by' => $reviewerId,
                'reviewed_at' => now(),
                'review_note' => $note,
            ]);

            if ($decision !== 'approved') {
                return;
            }

            $records = AttendanceRecord::query()
                ->where('student_id', $justification->student_id)
                ->whereBetween('created_at', [
                    $justification->date_from->copy()->startOfDay(),
                    $justification->date_to->copy()->endOfDay(),
                ])
                ->get();

            foreach ($records as $record) {
                $old = $record->status;
                $record->update(['status' => 'excused']);
                AttendanceRecordHistory::create([
                    'record_id' => $record->id,
                    'old_status' => $old,
                    'new_status' => 'excused',
                    'changed_by' => $reviewerId,
                    'reason' => 'Absence justification approved: '.$justification->reason,
                    'changed_at' => now(),
                ]);
            }
        });
    }
}
