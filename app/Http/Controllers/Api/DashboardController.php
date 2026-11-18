<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AcademicYear;
use App\Models\AnnualResult;
use App\Models\Assessment;
use App\Models\AttendanceRecord;
use App\Models\Charge;
use App\Models\Classroom;
use App\Models\Enrollment;
use App\Models\Payment;
use App\Models\Staff;
use App\Models\Student;
use App\Models\Term;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class DashboardController extends Controller
{
    public function index(Request $request)
    {
        $year = AcademicYear::current() ?? AcademicYear::orderByDesc('start_date')->first();
        $term = $year?->terms()->where('data_entry_open', true)->first() ?? $year?->terms()->first();
        $yearId = $year?->id;

        $students = Student::where('status', 'enrolled')->count();
        $classes = $yearId ? Classroom::where('academic_year_id', $yearId)->count() : 0;
        $staff = Staff::where('employment_status', 'active')->count();

        $attendanceToday = AttendanceRecord::query()
            ->join('attendance_sessions', 'attendance_sessions.id', '=', 'attendance_records.session_id')
            ->whereDate('attendance_sessions.session_date', today())
            ->selectRaw('attendance_records.status, count(*) as total')
            ->groupBy('attendance_records.status')
            ->pluck('total', 'status');

        $finance = $yearId ? [
            'billed' => (int) DB::table('charges')->where('academic_year_id', $yearId)->where('status', 'open')->sum('amount'),
            'collected' => (int) Payment::where('academic_year_id', $yearId)->where('status', 'verified')->sum('amount'),
            'collected_this_month' => (int) Payment::where('academic_year_id', $yearId)->where('status', 'verified')
                ->whereBetween('paid_at', [now()->startOfMonth(), now()->endOfMonth()])->sum('amount'),
            'pending_verification' => Payment::where('status', 'pending')->count(),
        ] : null;

        $outstanding = $yearId ? (int) DB::table('charges as c')
            ->where('c.academic_year_id', $yearId)
            ->where('c.status', 'open')
            ->selectRaw('(c.amount
                + COALESCE((SELECT SUM(a.amount) FROM charge_adjustments a WHERE a.charge_id = c.id AND a.status = \'approved\'), 0)
                - COALESCE((SELECT SUM(pa.amount) FROM payment_allocations pa WHERE pa.charge_id = c.id), 0)) as due')
            ->get()->sum('due') : 0;

        if ($finance) {
            $finance['outstanding'] = $outstanding;
        }

        $pendingValidation = Assessment::whereIn('status', ['open', 'submitted'])
            ->when($term, fn ($q) => $q->where('term_id', $term->id))->count();

        $needsSupport = $term
            ? DB::table('term_results')->where('term_id', $term->id)->where('needs_support', true)->count()
            : 0;

        $pendingPromotions = $yearId
            ? AnnualResult::whereIn('decision', ['pending', 'council_review'])->whereNull('decided_at')->count()
            : 0;

        $recent = \Spatie\Activitylog\Models\Activity::with('causer')
            ->latest()->limit(8)->get()
            ->map(fn ($a) => [
                'id' => $a->id,
                'description' => $a->description,
                'causer' => $a->causer?->name,
                'at' => $a->created_at?->diffForHumans(),
            ]);

        $enrollmentTrend = $yearId ? Enrollment::query()
            ->join('classes', 'classes.id', '=', 'enrollments.class_id')
            ->join('levels', 'levels.id', '=', 'classes.level_id')
            ->where('enrollments.academic_year_id', $yearId)
            ->whereNull('enrollments.end_date')
            ->selectRaw('levels.name as level, count(*) as total')
            ->groupBy('levels.name')->orderBy('levels.name')->get() : [];

        return [
            'year' => $year?->only(['id', 'name', 'start_date', 'end_date', 'is_current']),
            'term' => $term?->only(['id', 'name', 'sequence']),
            'counts' => compact('students', 'classes', 'staff'),
            'attendance_today' => $attendanceToday,
            'finance' => $finance,
            'pending_validation' => $pendingValidation,
            'needs_support' => $needsSupport,
            'pending_promotions' => $pendingPromotions,
            'recent_activity' => $recent,
            'enrollment_by_level' => $enrollmentTrend,
        ];
    }
}
