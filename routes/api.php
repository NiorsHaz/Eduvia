<?php

use App\Http\Controllers\Api\AttendanceController;
use App\Http\Controllers\Api\AuditController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\ChargeController;
use App\Http\Controllers\Api\ClassroomController;
use App\Http\Controllers\Api\DashboardController;
use App\Http\Controllers\Api\ExpenseController;
use App\Http\Controllers\Api\FeeController;
use App\Http\Controllers\Api\FinanceReportController;
use App\Http\Controllers\Api\GradebookController;
use App\Http\Controllers\Api\GuardianController;
use App\Http\Controllers\Api\PaymentController;
use App\Http\Controllers\Api\ReferenceController;
use App\Http\Controllers\Api\ResultsController;
use App\Http\Controllers\Api\SettingsController;
use App\Http\Controllers\Api\StaffController;
use App\Http\Controllers\Api\StudentController;
use App\Http\Controllers\Api\TimetableController;
use App\Http\Controllers\Api\UserController;
use App\Http\Middleware\EnsureAccountIsActive;
use App\Http\Middleware\EnsurePasswordIsCurrent;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->group(function () {
    Route::post('auth/login', [AuthController::class, 'login'])->middleware('throttle:20,1');

    Route::middleware(['auth:sanctum', EnsureAccountIsActive::class, EnsurePasswordIsCurrent::class])->group(function () {
        Route::get('auth/me', [AuthController::class, 'me']);
        Route::post('auth/logout', [AuthController::class, 'logout']);
        Route::post('auth/password', [AuthController::class, 'changePassword']);

        Route::get('dashboard', [DashboardController::class, 'index'])->middleware('permission:dashboard.view');

        // ---- Reference data (permission checked per resource) ----------
        Route::get('reference/{resource}', [ReferenceController::class, 'index']);
        Route::post('reference/{resource}', [ReferenceController::class, 'store']);
        Route::match(['put', 'patch'], 'reference/{resource}/{id}', [ReferenceController::class, 'update']);
        Route::delete('reference/{resource}/{id}', [ReferenceController::class, 'destroy']);

        // ---- Students ---------------------------------------------------
        Route::middleware('permission:students.view')->group(function () {
            Route::get('students', [StudentController::class, 'index']);
            Route::get('students/{student}', [StudentController::class, 'show']);
        });
        Route::middleware('permission:students.manage')->group(function () {
            Route::post('students', [StudentController::class, 'store']);
            Route::put('students/{student}', [StudentController::class, 'update']);
            Route::delete('students/{student}', [StudentController::class, 'destroy']);
            Route::post('students/{student}/enroll', [StudentController::class, 'enroll']);
            Route::post('students/import', [StudentController::class, 'import']);
        });
        Route::post('students/{student}/health', [StudentController::class, 'health'])->middleware('permission:students.medical');

        // ---- Guardians ---------------------------------------------------
        Route::middleware('permission:guardians.view')->group(function () {
            Route::get('guardians', [GuardianController::class, 'index']);
            Route::get('guardians/{guardian}', [GuardianController::class, 'show']);
        });
        Route::middleware('permission:guardians.manage')->group(function () {
            Route::post('guardians', [GuardianController::class, 'store']);
            Route::put('guardians/{guardian}', [GuardianController::class, 'update']);
            Route::delete('guardians/{guardian}', [GuardianController::class, 'destroy']);
            Route::post('students/{student}/guardians', [GuardianController::class, 'link']);
            Route::post('guardian-links/{link}/verify', [GuardianController::class, 'verify']);
            Route::post('guardian-links/{link}/revoke', [GuardianController::class, 'revoke']);
        });

        // ---- Academic structure / classes -------------------------------
        Route::middleware('permission:academics.view')->group(function () {
            Route::get('classes', [ClassroomController::class, 'index']);
            Route::get('classes/{classroom}', [ClassroomController::class, 'show']);
            Route::get('classes/{classroom}/roster', [ClassroomController::class, 'roster']);
        });
        Route::middleware('permission:academics.manage')->group(function () {
            Route::post('classes/{classroom}/subjects', [ClassroomController::class, 'assignSubject']);
            Route::delete('class-subject-teachers/{assignment}', [ClassroomController::class, 'unassignSubject']);
        });

        // ---- Staff directory and login accounts ---------------------------
        Route::get('staff', [StaffController::class, 'index'])->middleware('permission:staff.view');

        // ---- Timetable ---------------------------------------------------
        Route::middleware('permission:timetable.view')->group(function () {
            Route::get('timetable', [TimetableController::class, 'index']);
            Route::get('timetable/{entry}/exceptions', [TimetableController::class, 'exceptions']);
        });
        Route::middleware('permission:timetable.manage')->group(function () {
            Route::post('timetable', [TimetableController::class, 'store']);
            Route::put('timetable/{entry}', [TimetableController::class, 'update']);
            Route::delete('timetable/{entry}', [TimetableController::class, 'destroy']);
            Route::post('timetable/{entry}/exceptions', [TimetableController::class, 'storeException']);
        });

        // ---- Attendance --------------------------------------------------
        Route::middleware('permission:attendance.view')->group(function () {
            Route::get('attendance', [AttendanceController::class, 'index']);
            Route::get('attendance/roster', [AttendanceController::class, 'roster']);
            Route::get('attendance/{session}', [AttendanceController::class, 'show']);
            Route::get('attendance-justifications', [AttendanceController::class, 'justifications']);
        });
        Route::middleware('permission:attendance.manage')->group(function () {
            Route::post('attendance', [AttendanceController::class, 'store']);
            Route::put('attendance-records/{record}', [AttendanceController::class, 'updateRecord']);
            Route::post('attendance-justifications', [AttendanceController::class, 'storeJustification']);
            Route::post('attendance-justifications/{justification}/review', [AttendanceController::class, 'reviewJustification']);
        });

        // ---- Gradebook ---------------------------------------------------
        Route::middleware('permission:grading.view')->group(function () {
            Route::get('assessments', [GradebookController::class, 'index']);
            Route::get('assessments/{assessment}', [GradebookController::class, 'show']);
            Route::get('gradebook/missing', [GradebookController::class, 'missing']);
            Route::get('gradebook/stats', [GradebookController::class, 'stats']);
            Route::get('gradebook/events', [GradebookController::class, 'events']);
        });
        Route::middleware('permission:grading.entry')->group(function () {
            Route::post('assessments', [GradebookController::class, 'store']);
            Route::put('assessments/{assessment}', [GradebookController::class, 'update']);
            Route::delete('assessments/{assessment}', [GradebookController::class, 'destroy']);
            Route::post('assessments/{assessment}/grades', [GradebookController::class, 'saveGrades']);
        });
        Route::post('gradebook/workflow', [GradebookController::class, 'workflow'])->middleware('permission:grading.validate');

        // ---- Results, promotions, report cards ---------------------------
        Route::middleware('permission:results.view')->group(function () {
            Route::get('results/term', [ResultsController::class, 'termResults']);
            Route::get('results/subjects', [ResultsController::class, 'subjectResults']);
            Route::get('results/annual', [ResultsController::class, 'annual']);
            Route::get('results/support', [ResultsController::class, 'support']);
            Route::get('results/rules', [ResultsController::class, 'rules']);
            Route::get('report-cards', [ResultsController::class, 'reportCards']);
            Route::get('report-cards/{reportCard}', [ResultsController::class, 'showReportCard']);
        });
        Route::middleware('permission:results.manage')->group(function () {
            Route::post('results/compute-term', [ResultsController::class, 'computeTerm']);
            Route::post('results/compute-annual', [ResultsController::class, 'computeAnnual']);
            Route::post('results/promotion-preview', [ResultsController::class, 'promotePreview']);
            Route::post('results/{annualResult}/confirm', [ResultsController::class, 'confirmDecision']);
            Route::post('students/{student}/report-card', [ResultsController::class, 'buildReportCard']);
            Route::post('report-cards/build-class', [ResultsController::class, 'buildClassReportCards']);
            Route::post('report-cards/{reportCard}/publish', [ResultsController::class, 'publishReportCard']);
        });

        // ---- Settings and configurable rules -----------------------------
        Route::middleware('permission:settings.view')->group(function () {
            Route::get('settings', [SettingsController::class, 'registry']);
            Route::get('grading-policies', [SettingsController::class, 'policies']);
            Route::get('grading-policies/{policy}', [SettingsController::class, 'showPolicy']);
            Route::get('grading-policies/{policy}/subject-rules', [SettingsController::class, 'subjectRules']);
            Route::get('grading-policies/{policy}/promotion-rules', [SettingsController::class, 'promotionRules']);
            Route::get('rule-metrics', [SettingsController::class, 'metrics']);
        });
        Route::middleware('permission:settings.manage')->group(function () {
            Route::put('settings', [SettingsController::class, 'updateSetting']);
            Route::post('grading-policies', [SettingsController::class, 'storePolicy']);
            Route::put('grading-policies/{policy}', [SettingsController::class, 'updatePolicy']);
            Route::post('grading-policies/{policy}/lock', [SettingsController::class, 'lockPolicy']);
            Route::post('grading-policies/{policy}/clone', [SettingsController::class, 'clonePolicy']);
            Route::post('grading-policies/{policy}/subject-rules', [SettingsController::class, 'storeSubjectRule']);
            Route::delete('grading-policies/{policy}/subject-rules/{rule}', [SettingsController::class, 'destroySubjectRule']);
            Route::post('grading-policies/{policy}/rule-sets', [SettingsController::class, 'storeRuleSet']);
            Route::post('rule-sets/{ruleSet}/rules', [SettingsController::class, 'storePromotionRule']);
            Route::put('promotion-rules/{rule}', [SettingsController::class, 'updatePromotionRule']);
            Route::delete('promotion-rules/{rule}', [SettingsController::class, 'destroyPromotionRule']);
        });

        // ---- Finance -----------------------------------------------------
        Route::middleware('permission:finance.view')->group(function () {
            Route::get('charges', [ChargeController::class, 'index']);
            Route::get('charges/{charge}/adjustments', [ChargeController::class, 'adjustments']);
            Route::get('finance/balances', [ChargeController::class, 'balances']);
            Route::get('payments', [PaymentController::class, 'index']);
            Route::get('payments/{payment}', [PaymentController::class, 'show']);
            Route::get('receipts', [PaymentController::class, 'receipts']);
            Route::get('receipts/{receipt}', [PaymentController::class, 'receipt']);
            Route::get('finance/reversals', [PaymentController::class, 'reversals']);
            Route::get('finance/summary', [FinanceReportController::class, 'summary']);
            Route::get('finance/cash-closings', [FinanceReportController::class, 'cashClosings']);
            Route::get('finance/generation-runs', fn () => \App\Models\FeeGenerationRun::orderByDesc('id')->limit(50)->get());
            Route::get('expenses', [ExpenseController::class, 'index']);
            Route::get('finance/student-discounts', [FeeController::class, 'studentDiscounts']);
        });
        Route::middleware('permission:finance.manage')->group(function () {
            Route::post('charges', [ChargeController::class, 'store']);
            Route::post('charges/{charge}/cancel', [ChargeController::class, 'cancel']);
            Route::post('charges/{charge}/adjustments', [ChargeController::class, 'storeAdjustment']);
            Route::post('payments', [PaymentController::class, 'store']);
            Route::post('payments/{payment}/verify', [PaymentController::class, 'verify']);
            Route::post('payments/{payment}/allocate', [PaymentController::class, 'allocate']);
            Route::post('payments/{payment}/reversal', [PaymentController::class, 'requestReversal']);
            Route::post('finance/reversals/{reversal}/approve', [PaymentController::class, 'approveReversal']);
            Route::post('finance/cash-closings', [FinanceReportController::class, 'closeCash']);
            Route::post('finance/fees/generate', [FeeController::class, 'generate']);
            Route::post('finance/penalties/run', [FeeController::class, 'runPenalties']);
            Route::post('finance/student-discounts', [FeeController::class, 'grantDiscount']);
            Route::post('expenses', [ExpenseController::class, 'store']);
        });
        Route::post('finance/adjustments/{adjustment}/decide', [ChargeController::class, 'decideAdjustment'])->middleware('permission:finance.approve');
        Route::post('finance/student-discounts/{studentDiscount}/decide', [FeeController::class, 'decideDiscount'])->middleware('permission:finance.approve');
        Route::post('expenses/{expense}/decide', [ExpenseController::class, 'decide'])->middleware('permission:finance.approve');

        // ---- Users and audit ---------------------------------------------
        Route::middleware('permission:users.manage')->group(function () {
            Route::get('users', [UserController::class, 'index']);
            Route::post('users', [UserController::class, 'store']);
            Route::put('users/{user}', [UserController::class, 'update']);
            Route::delete('users/{user}', [UserController::class, 'destroy']);
            Route::get('roles', [UserController::class, 'roles']);
            Route::post('users/{user}/grant-role', [UserController::class, 'grantTemporaryRole']);
            Route::get('users/{user}/login-history', [UserController::class, 'loginHistory']);
        });
        Route::get('audit', [AuditController::class, 'index'])->middleware('permission:audit.view');
        Route::get('audit/logs', [AuditController::class, 'logs'])->middleware('permission:audit.view');
    });
});
