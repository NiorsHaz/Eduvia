<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AcademicYear;
use App\Models\Charge;
use App\Models\Classroom;
use App\Models\Enrollment;
use App\Models\Student;
use App\Models\StudentHealthRecord;
use App\Services\SettingService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class StudentController extends Controller
{
    public function __construct(private readonly SettingService $settings) {}

    public function index(Request $request)
    {
        $query = Student::query()
            ->leftJoin('enrollments', function ($join) {
                $join->on('enrollments.student_id', '=', 'students.id')->whereNull('enrollments.end_date');
            })
            ->leftJoin('classes', 'classes.id', '=', 'enrollments.class_id')
            ->leftJoin('levels', 'levels.id', '=', 'classes.level_id')
            ->select('students.*', 'classes.name as class_name', 'classes.id as class_id', 'levels.code as level_code')
            ->when($request->filled('q'), function ($q) use ($request) {
                $term = '%'.$request->string('q').'%';
                $q->where(function ($w) use ($term) {
                    $w->where('students.first_name', 'like', $term)
                        ->orWhere('students.last_name', 'like', $term)
                        ->orWhere('students.student_number', 'like', $term)
                        ->orWhere('students.phone', 'like', $term);
                });
            })
            ->when($request->filled('status'), fn ($q) => $q->where('students.status', $request->input('status')))
            ->when($request->filled('class_id'), fn ($q) => $q->where('enrollments.class_id', $request->integer('class_id')))
            ->when($request->filled('level_id'), fn ($q) => $q->where('classes.level_id', $request->integer('level_id')))
            ->orderBy('students.last_name')->orderBy('students.first_name');

        return $query->paginate(min(200, (int) $request->input('per_page', 25)));
    }

    public function store(Request $request)
    {
        $data = $request->validate($this->rules());

        $student = DB::transaction(function () use ($data) {
            $classId = $data['class_id'] ?? null;
            unset($data['class_id']);
            $data['student_number'] = $this->nextStudentNumber();
            $student = Student::create($data);

            if (! empty($classId)) {
                $this->openEnrollment($student, (int) $classId, $data['admission_date'] ?? now()->toDateString(), $request->user()->id);
            }

            return $student;
        });

        activity('students')->performedOn($student)->log('student.created');

        return response()->json($this->present($student->fresh()), 201);
    }

    public function show(Student $student, Request $request)
    {
        $student->load(['guardians', 'category']);
        $enrollments = Enrollment::with(['classroom.level', 'classroom.track', 'academicYear'])
            ->where('student_id', $student->id)->orderByDesc('start_date')->get();

        $yearId = AcademicYear::current()?->id;
        $balance = $yearId ? Charge::balancesForStudent($student->id, $yearId) : null;

        $health = null;
        if ($request->user()->can('students.medical')) {
            $health = StudentHealthRecord::where('student_id', $student->id)->first();
        }

        return [
            'student' => $student,
            'enrollments' => $enrollments,
            'health' => $health,
            'balance' => $balance,
            'attendance' => $this->attendanceSummary($student->id),
        ];
    }

    public function update(Request $request, Student $student)
    {
        $data = $request->validate($this->rules($student));
        $student->update($data);

        activity('students')->performedOn($student)->log('student.updated');

        return $this->present($student->fresh());
    }

    public function destroy(Student $student)
    {
        // Soft delete: institutional history must survive.
        $student->delete();
        activity('students')->performedOn($student)->log('student.archived');

        return response()->noContent();
    }

    public function enroll(Request $request, Student $student)
    {
        $data = $request->validate([
            'class_id' => 'required|integer|exists:classes,id',
            'start_date' => 'required|date',
            'reason' => 'nullable|string|max:255',
        ]);

        DB::transaction(function () use ($student, $data, $request) {
            $class = Classroom::findOrFail($data['class_id']);

            $active = Enrollment::where('student_id', $student->id)
                ->where('academic_year_id', $class->academic_year_id)
                ->whereNull('end_date')->first();

            if ($active && (int) $active->class_id === (int) $class->id) {
                throw ValidationException::withMessages(['class_id' => 'The student is already in this class.']);
            }

            if ($active) {
                $active->update([
                    'end_date' => $data['start_date'],
                    'status' => 'transferred',
                ]);
            }

            Enrollment::create([
                'student_id' => $student->id,
                'class_id' => $class->id,
                'academic_year_id' => $class->academic_year_id,
                'start_date' => $data['start_date'],
                'status' => 'active',
                'created_by' => $request->user()->id,
                'created_at' => now(),
            ]);

            $student->update(['status' => 'enrolled']);
        });

        return $this->present($student->fresh());
    }

    public function health(Request $request, Student $student)
    {
        $data = $request->validate([
            'allergies' => 'nullable|string',
            'medical_conditions' => 'nullable|string',
            'medications' => 'nullable|string',
            'emergency_notes' => 'nullable|string',
        ]);

        $record = StudentHealthRecord::updateOrCreate(
            ['student_id' => $student->id],
            $data + ['updated_by' => $request->user()->id, 'updated_at' => now()]
        );

        activity('students')->performedOn($student)->log('student.health_updated');

        return $record;
    }

    /**
     * CSV import with validation report and batch tracking.
     * Columns: first_name,last_name,date_of_birth,gender,class_id,guardian_name,guardian_phone
     */
    public function import(Request $request)
    {
        $request->validate(['file' => 'required|file|mimes:csv,txt|max:2048']);

        $batch = \App\Models\ImportBatch::create([
            'entity' => 'students',
            'file_name' => $request->file('file')->getClientOriginalName(),
            'status' => 'uploaded',
            'imported_by' => $request->user()->id,
            'created_at' => now(),
        ]);

        $handle = fopen($request->file('file')->getRealPath(), 'r');
        $header = array_map(fn ($h) => trim(strtolower($h)), fgetcsv($handle) ?: []);
        $required = ['first_name', 'last_name', 'date_of_birth'];
        $errors = [];
        $rows = [];
        $line = 1;

        while (($row = fgetcsv($handle)) !== false) {
            $line++;
            if (count(array_filter($row)) === 0) {
                continue;
            }
            $data = array_combine(array_slice($header, 0, count($row)), $row) ?: [];

            foreach ($required as $field) {
                if (empty($data[$field])) {
                    $errors[] = ['row_number' => $line, 'field' => $field, 'message' => "Missing {$field}"];
                }
            }

            if (! empty($data['date_of_birth']) && strtotime($data['date_of_birth']) === false) {
                $errors[] = ['row_number' => $line, 'field' => 'date_of_birth', 'message' => 'Invalid date'];
            }

            $rows[] = ['line' => $line, 'data' => $data];
        }
        fclose($handle);

        $imported = 0;

        if (! $errors) {
            DB::transaction(function () use ($rows, &$imported, $batch, $request) {
                foreach ($rows as $row) {
                    $data = $row['data'];
                    $student = Student::create([
                        'student_number' => $this->nextStudentNumber(),
                        'first_name' => $data['first_name'],
                        'last_name' => $data['last_name'],
                        'date_of_birth' => $data['date_of_birth'],
                        'gender' => $data['gender'] ?? null,
                        'status' => 'enrolled',
                        'admission_date' => now()->toDateString(),
                    ]);

                    if (! empty($data['class_id'])) {
                        try {
                            $this->openEnrollment($student, (int) $data['class_id'], now()->toDateString(), $request->user()->id);
                        } catch (\Throwable $e) {
                            \App\Models\ImportBatchError::create([
                                'batch_id' => $batch->id,
                                'row_number' => $row['line'],
                                'field' => 'class_id',
                                'message' => $e->getMessage(),
                            ]);
                        }
                    }

                    $imported++;
                }
            });
        } else {
            foreach ($errors as $error) {
                \App\Models\ImportBatchError::create($error + ['batch_id' => $batch->id]);
            }
        }

        $batch->update([
            'status' => $errors ? 'failed_validation' : 'committed',
            'total_rows' => count($rows),
            'valid_rows' => $imported,
            'error_rows' => count($errors),
            'committed_at' => $errors ? null : now(),
        ]);

        return response()->json([
            'batch' => $batch,
            'errors' => \App\Models\ImportBatchError::where('batch_id', $batch->id)->limit(100)->get(),
            'imported' => $imported,
        ], $errors ? 422 : 201);
    }

    private function openEnrollment(Student $student, int $classId, string $startDate, int $userId): Enrollment
    {
        $class = Classroom::findOrFail($classId);

        $active = Enrollment::where('student_id', $student->id)
            ->whereNull('end_date')->first();

        if ($active) {
            $active->update(['end_date' => $startDate, 'status' => 'transferred']);
        }

        return Enrollment::create([
            'student_id' => $student->id,
            'class_id' => $class->id,
            'academic_year_id' => $class->academic_year_id,
            'start_date' => $startDate,
            'status' => 'active',
            'created_by' => $userId,
            'created_at' => now(),
        ]);
    }

    private function nextStudentNumber(): string
    {
        $pattern = (string) ($this->settings->get('students.number_format') ?? '{YEAR}-{SEQ:5}');
        $year = AcademicYear::current()?->name ?? now()->format('Y');
        $base = str_replace('{YEAR}', $year, $pattern);

        $digits = 5;
        if (preg_match('/\{SEQ:(\d+)\}/', $base, $match)) {
            $digits = (int) $match[1];
            $prefix = preg_replace('/\{SEQ:\d+\}/', '', $base);
        } else {
            $prefix = $base;
        }

        $sequence = Student::where('student_number', 'like', $prefix.'%')->count() + 1;

        do {
            $candidate = $prefix.str_pad((string) $sequence, $digits, '0', STR_PAD_LEFT);
            $sequence++;
        } while (Student::where('student_number', $candidate)->exists());

        return $candidate;
    }

    private function rules(?Student $student = null): array
    {
        return [
            'first_name' => 'required|string|max:120',
            'last_name' => 'required|string|max:120',
            'gender' => 'nullable|in:female,male,other',
            'date_of_birth' => 'required|date|before:today',
            'place_of_birth' => 'nullable|string|max:120',
            'nationality' => 'nullable|string|max:60',
            'address' => 'nullable|string',
            'phone' => 'nullable|string|max:40',
            'email' => 'nullable|email',
            'previous_school' => 'nullable|string|max:160',
            'status' => 'in:applicant,enrolled,suspended,withdrawn,transferred_out,graduated,alumni',
            'admission_date' => 'nullable|date',
            'exit_date' => 'nullable|date',
            'exit_reason' => 'nullable|string|max:160',
            'student_category_id' => 'nullable|integer|exists:student_categories,id',
            'class_id' => 'sometimes|nullable|integer|exists:classes,id',
        ];
    }

    private function present(Student $student): array
    {
        $enrollment = $student->currentEnrollment()->with('classroom.level')->first();

        return [
            'student' => $student,
            'class' => $enrollment?->classroom?->label,
        ];
    }

    private function attendanceSummary(int $studentId): array
    {
        return DB::table('attendance_records')
            ->where('student_id', $studentId)
            ->selectRaw('status, count(*) as total')
            ->groupBy('status')->pluck('total', 'status')->all();
    }
}
