<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 1: accounts, people, academic structure, timetable, attendance, imports.
 * Mirrors phase1_schema.sql. Portable between PostgreSQL and SQLite; the
 * Postgres-only exclusion constraints live in 2026_11_18_200400_postgres_integrity.php.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('campuses', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('code')->unique();
            $table->text('address')->nullable();
            $table->string('phone', 40)->nullable();
            $table->timestamps();
        });

        Schema::table('users', function (Blueprint $table) {
            $table->foreignId('campus_id')->nullable()->constrained()->nullOnDelete();
            $table->string('phone', 40)->nullable()->unique();
            $table->string('locale', 5)->default('fr');
            $table->string('status', 20)->default('active');
            $table->timestamp('last_login_at')->nullable();
            $table->boolean('must_change_password')->default(false);
            $table->softDeletes();
        });
        Schema::table('users', function (Blueprint $table) {
            $table->string('email')->nullable()->change();
        });

        Schema::create('login_history', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained()->cascadeOnDelete();
            $table->string('identifier');
            $table->boolean('success')->default(true);
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->timestamp('created_at')->useCurrent();
            $table->index(['user_id', 'created_at']);
        });

        Schema::create('temporary_role_grants', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('role_name');
            $table->foreignId('granted_by')->constrained('users');
            $table->timestamp('starts_at');
            $table->timestamp('expires_at');
            $table->text('reason')->nullable();
            $table->timestamp('revoked_at')->nullable();
        });

        Schema::create('departments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('campus_id')->nullable()->constrained()->nullOnDelete();
            $table->string('name');
            $table->unsignedBigInteger('head_staff_id')->nullable();
            $table->timestamps();
            $table->unique(['campus_id', 'name']);
        });

        Schema::create('staff', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->unique()->constrained()->nullOnDelete();
            $table->foreignId('campus_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('department_id')->nullable()->constrained()->nullOnDelete();
            $table->string('employee_number')->unique();
            $table->string('first_name');
            $table->string('last_name');
            $table->string('gender', 10)->nullable();
            $table->date('date_of_birth')->nullable();
            $table->string('national_id')->nullable();
            $table->string('phone', 40)->nullable();
            $table->string('email')->nullable();
            $table->text('address')->nullable();
            $table->string('position');
            $table->string('staff_type', 20)->default('teacher');
            $table->text('qualifications')->nullable();
            $table->string('employment_status', 20)->default('active');
            $table->date('hire_date')->nullable();
            $table->date('contract_end_date')->nullable();
            $table->string('photo_path')->nullable();
            $table->timestamps();
            $table->softDeletes();
            $table->index(['last_name', 'first_name']);
        });

        Schema::table('departments', function (Blueprint $table) {
            $table->foreign('head_staff_id')->references('id')->on('staff')->nullOnDelete();
        });

        Schema::create('academic_years', function (Blueprint $table) {
            $table->id();
            $table->foreignId('campus_id')->nullable()->constrained()->nullOnDelete();
            $table->string('name');
            $table->date('start_date');
            $table->date('end_date');
            $table->string('status', 20)->default('planned');
            $table->boolean('is_current')->default(false);
            $table->timestamps();
            $table->unique(['campus_id', 'name']);
        });
        // Only one current academic year at a time.
        DB::statement('CREATE UNIQUE INDEX uq_one_current_year ON academic_years (is_current) WHERE is_current');

        Schema::create('terms', function (Blueprint $table) {
            $table->id();
            $table->foreignId('academic_year_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->smallInteger('sequence');
            $table->date('start_date');
            $table->date('end_date');
            $table->boolean('data_entry_open')->default(true);
            $table->decimal('weight', 4, 2)->default(1);
            $table->timestamps();
            $table->unique(['academic_year_id', 'sequence']);
        });

        Schema::create('calendar_periods', function (Blueprint $table) {
            $table->id();
            $table->foreignId('academic_year_id')->constrained()->cascadeOnDelete();
            $table->string('type', 20);
            $table->string('title');
            $table->date('start_date');
            $table->date('end_date');
            $table->timestamp('created_at')->useCurrent();
        });

        Schema::create('buildings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('campus_id')->nullable()->constrained()->nullOnDelete();
            $table->string('name');
            $table->unique(['campus_id', 'name']);
        });

        Schema::create('rooms', function (Blueprint $table) {
            $table->id();
            $table->foreignId('building_id')->nullable()->constrained()->nullOnDelete();
            $table->string('name');
            $table->string('room_type', 20)->default('classroom');
            $table->integer('capacity')->nullable();
            $table->boolean('is_active')->default(true);
            $table->unique(['building_id', 'name']);
        });

        Schema::create('levels', function (Blueprint $table) {
            $table->id();
            $table->string('code')->unique();
            $table->string('name');
            $table->string('cycle', 30)->nullable();
            $table->smallInteger('sort_order')->default(0);
            $table->timestamp('created_at')->useCurrent();
        });

        Schema::create('tracks', function (Blueprint $table) {
            $table->id();
            $table->string('code')->unique();
            $table->string('name');
        });

        Schema::create('classes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('academic_year_id')->constrained()->cascadeOnDelete();
            $table->foreignId('level_id')->constrained();
            $table->foreignId('track_id')->nullable()->constrained()->nullOnDelete();
            $table->string('name');
            $table->integer('capacity')->default(40);
            $table->foreignId('supervisor_staff_id')->nullable()->constrained('staff')->nullOnDelete();
            $table->foreignId('home_room_id')->nullable()->constrained('rooms')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();
            $table->unique(['academic_year_id', 'level_id', 'name']);
        });

        Schema::create('subjects', function (Blueprint $table) {
            $table->id();
            $table->string('code')->unique();
            $table->string('name');
            $table->boolean('is_optional')->default(false);
            $table->boolean('is_active')->default(true);
            $table->timestamp('created_at')->useCurrent();
        });

        Schema::create('level_subjects', function (Blueprint $table) {
            $table->id();
            $table->foreignId('academic_year_id')->constrained()->cascadeOnDelete();
            $table->foreignId('level_id')->constrained();
            $table->foreignId('track_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('subject_id')->constrained();
            $table->decimal('coefficient', 4, 2)->default(1);
            $table->decimal('weekly_hours', 4, 1)->nullable();
            $table->unique(['academic_year_id', 'level_id', 'track_id', 'subject_id'], 'uq_level_subject');
        });

        Schema::create('class_subject_teachers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('class_id')->constrained('classes')->cascadeOnDelete();
            $table->foreignId('subject_id')->constrained();
            $table->foreignId('staff_id')->constrained();
            $table->date('starts_on')->nullable();
            $table->date('ends_on')->nullable();
            $table->timestamp('created_at')->useCurrent();
            $table->unique(['class_id', 'subject_id', 'staff_id']);
            $table->index('staff_id');
        });

        Schema::create('guardians', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->unique()->constrained()->nullOnDelete();
            $table->string('first_name');
            $table->string('last_name');
            $table->string('phone', 40)->nullable();
            $table->string('phone_alt', 40)->nullable();
            $table->string('email')->nullable();
            $table->text('address')->nullable();
            $table->string('occupation')->nullable();
            $table->string('national_id')->nullable();
            $table->timestamps();
            $table->softDeletes();
            $table->index('phone');
            $table->index(['last_name', 'first_name']);
        });

        Schema::create('students', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->unique()->constrained()->nullOnDelete();
            $table->foreignId('campus_id')->nullable()->constrained()->nullOnDelete();
            $table->string('student_number')->unique();
            $table->string('first_name');
            $table->string('last_name');
            $table->string('gender', 10)->nullable();
            $table->date('date_of_birth');
            $table->string('place_of_birth')->nullable();
            $table->string('nationality')->nullable();
            $table->text('address')->nullable();
            $table->string('phone', 40)->nullable();
            $table->string('email')->nullable();
            $table->string('photo_path')->nullable();
            $table->string('previous_school')->nullable();
            $table->string('status', 20)->default('enrolled');
            $table->date('admission_date')->nullable();
            $table->date('exit_date')->nullable();
            $table->string('exit_reason')->nullable();
            $table->timestamps();
            $table->softDeletes();
            $table->index(['last_name', 'first_name']);
            $table->index('status');
        });

        Schema::create('student_health_records', function (Blueprint $table) {
            $table->id();
            $table->foreignId('student_id')->unique()->constrained()->cascadeOnDelete();
            $table->text('allergies')->nullable();
            $table->text('medical_conditions')->nullable();
            $table->text('medications')->nullable();
            $table->text('emergency_notes')->nullable();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('updated_at')->useCurrent();
        });

        Schema::create('student_guardians', function (Blueprint $table) {
            $table->id();
            $table->foreignId('student_id')->constrained()->cascadeOnDelete();
            $table->foreignId('guardian_id')->constrained()->cascadeOnDelete();
            $table->string('relationship', 30);
            $table->boolean('is_primary_contact')->default(false);
            $table->boolean('is_emergency_contact')->default(false);
            $table->boolean('is_authorized_pickup')->default(false);
            $table->boolean('can_view_academic')->default(true);
            $table->boolean('can_view_finance')->default(true);
            $table->timestamp('verified_at')->nullable();
            $table->foreignId('verified_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('revoked_at')->nullable();
            $table->foreignId('revoked_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('revoke_reason')->nullable();
            $table->timestamps();
            $table->unique(['student_id', 'guardian_id']);
            $table->index('guardian_id');
        });
        DB::statement('CREATE UNIQUE INDEX uq_primary_contact ON student_guardians (student_id) WHERE is_primary_contact AND revoked_at IS NULL');

        Schema::create('applications', function (Blueprint $table) {
            $table->id();
            $table->foreignId('academic_year_id')->constrained()->cascadeOnDelete();
            $table->foreignId('level_id')->constrained();
            $table->foreignId('student_id')->nullable()->constrained()->nullOnDelete();
            $table->string('applicant_first_name');
            $table->string('applicant_last_name');
            $table->date('date_of_birth');
            $table->string('guardian_name')->nullable();
            $table->string('guardian_phone', 40)->nullable();
            $table->string('guardian_email')->nullable();
            $table->string('previous_school')->nullable();
            $table->string('status', 20)->default('submitted');
            $table->boolean('documents_complete')->default(false);
            $table->foreignId('decision_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('decision_at')->nullable();
            $table->text('decision_note')->nullable();
            $table->timestamps();
            $table->index(['academic_year_id', 'status']);
        });

        Schema::create('enrollments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('student_id')->constrained()->cascadeOnDelete();
            $table->foreignId('class_id')->constrained('classes');
            $table->foreignId('academic_year_id')->constrained();
            $table->date('start_date');
            $table->date('end_date')->nullable();
            $table->string('status', 20)->default('active');
            $table->string('outcome', 20)->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('created_at')->useCurrent();
            $table->index('student_id');
        });

        Schema::create('documents', function (Blueprint $table) {
            $table->id();
            $table->string('owner_type', 30);
            $table->unsignedBigInteger('owner_id');
            $table->string('category');
            $table->string('title');
            $table->string('storage_path');
            $table->string('mime_type')->nullable();
            $table->unsignedBigInteger('size_bytes')->nullable();
            $table->integer('version')->default(1);
            $table->string('status', 20)->default('active');
            $table->foreignId('uploaded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('created_at')->useCurrent();
            $table->timestamp('archived_at')->nullable();
            $table->index(['owner_type', 'owner_id']);
        });

        Schema::create('timetable_entries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('academic_year_id')->constrained()->cascadeOnDelete();
            $table->foreignId('class_id')->constrained('classes')->cascadeOnDelete();
            $table->foreignId('subject_id')->constrained();
            $table->foreignId('staff_id')->constrained();
            $table->foreignId('room_id')->nullable()->constrained()->nullOnDelete();
            $table->smallInteger('day_of_week');
            $table->time('start_time');
            $table->time('end_time');
            $table->date('valid_from');
            $table->date('valid_to')->nullable();
            $table->timestamps();
            $table->index(['class_id', 'day_of_week']);
            $table->index(['staff_id', 'day_of_week']);
        });

        Schema::create('timetable_exceptions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('timetable_entry_id')->constrained()->cascadeOnDelete();
            $table->date('exception_date');
            $table->string('type', 20);
            $table->foreignId('substitute_staff_id')->nullable()->constrained('staff')->nullOnDelete();
            $table->foreignId('substitute_room_id')->nullable()->constrained('rooms')->nullOnDelete();
            $table->text('reason')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('created_at')->useCurrent();
            $table->unique(['timetable_entry_id', 'exception_date']);
        });

        Schema::create('attendance_sessions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('class_id')->constrained('classes')->cascadeOnDelete();
            $table->foreignId('term_id')->nullable()->constrained()->nullOnDelete();
            $table->date('session_date');
            $table->foreignId('timetable_entry_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('taken_by')->constrained('users');
            $table->timestamp('submitted_at')->nullable();
            $table->uuid('client_uuid')->nullable()->unique();
            $table->timestamps();
            $table->unique(['class_id', 'session_date', 'timetable_entry_id'], 'uq_att_session');
        });

        Schema::create('attendance_records', function (Blueprint $table) {
            $table->id();
            $table->foreignId('session_id')->constrained('attendance_sessions')->cascadeOnDelete();
            $table->foreignId('student_id')->constrained()->cascadeOnDelete();
            $table->string('status', 20);
            $table->smallInteger('minutes_late')->nullable();
            $table->text('note')->nullable();
            $table->foreignId('marked_by')->constrained('users');
            $table->timestamps();
            $table->unique(['session_id', 'student_id']);
            $table->index(['student_id', 'status']);
        });

        Schema::create('attendance_record_history', function (Blueprint $table) {
            $table->id();
            $table->foreignId('record_id')->constrained('attendance_records')->cascadeOnDelete();
            $table->string('old_status', 20)->nullable();
            $table->string('new_status', 20);
            $table->foreignId('changed_by')->constrained('users');
            $table->text('reason')->nullable();
            $table->timestamp('changed_at')->useCurrent();
        });

        Schema::create('absence_justifications', function (Blueprint $table) {
            $table->id();
            $table->foreignId('attendance_record_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('student_id')->constrained()->cascadeOnDelete();
            $table->foreignId('submitted_by')->constrained('users');
            $table->date('date_from');
            $table->date('date_to');
            $table->text('reason');
            $table->foreignId('attachment_document_id')->nullable()->constrained('documents')->nullOnDelete();
            $table->string('status', 30)->default('pending');
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->text('review_note')->nullable();
            $table->timestamp('created_at')->useCurrent();
        });

        Schema::create('import_batches', function (Blueprint $table) {
            $table->id();
            $table->string('entity', 30);
            $table->string('file_name');
            $table->string('status', 30)->default('uploaded');
            $table->integer('total_rows')->nullable();
            $table->integer('valid_rows')->nullable();
            $table->integer('error_rows')->nullable();
            $table->foreignId('imported_by')->constrained('users');
            $table->timestamp('committed_at')->nullable();
            $table->timestamp('created_at')->useCurrent();
        });

        Schema::create('import_batch_errors', function (Blueprint $table) {
            $table->id();
            $table->foreignId('batch_id')->constrained('import_batches')->cascadeOnDelete();
            $table->integer('row_number');
            $table->string('field')->nullable();
            $table->text('message');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('import_batch_errors');
        Schema::dropIfExists('import_batches');
        Schema::dropIfExists('absence_justifications');
        Schema::dropIfExists('attendance_record_history');
        Schema::dropIfExists('attendance_records');
        Schema::dropIfExists('attendance_sessions');
        Schema::dropIfExists('timetable_exceptions');
        Schema::dropIfExists('timetable_entries');
        Schema::dropIfExists('documents');
        Schema::dropIfExists('enrollments');
        Schema::dropIfExists('applications');
        Schema::dropIfExists('student_guardians');
        Schema::dropIfExists('student_health_records');
        Schema::dropIfExists('students');
        Schema::dropIfExists('guardians');
        Schema::dropIfExists('class_subject_teachers');
        Schema::dropIfExists('level_subjects');
        Schema::dropIfExists('subjects');
        Schema::dropIfExists('classes');
        Schema::dropIfExists('tracks');
        Schema::dropIfExists('levels');
        Schema::dropIfExists('rooms');
        Schema::dropIfExists('buildings');
        Schema::dropIfExists('calendar_periods');
        Schema::dropIfExists('terms');
        DB::statement('DROP INDEX IF EXISTS uq_one_current_year');
        Schema::dropIfExists('academic_years');
        Schema::table('departments', function (Blueprint $table) {
            $table->dropForeign(['head_staff_id']);
        });
        Schema::dropIfExists('staff');
        Schema::dropIfExists('departments');
        Schema::dropIfExists('temporary_role_grants');
        Schema::dropIfExists('login_history');
        Schema::table('users', function (Blueprint $table) {
            $table->dropForeign(['campus_id']);
            $table->dropColumn(['campus_id', 'phone', 'locale', 'status', 'last_login_at', 'must_change_password', 'deleted_at']);
        });
        Schema::dropIfExists('campuses');
    }
};
