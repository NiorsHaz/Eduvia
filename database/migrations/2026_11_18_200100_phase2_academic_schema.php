<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 2 + 2b: grading policy, gradebook, validation workflow, examinations,
 * computed result snapshots, report cards, homework, configurable rules.
 * Mirrors phase2_schema.sql and phase2b_configurable_rules.sql.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('grading_scales', function (Blueprint $table) {
            $table->id();
            $table->string('name')->unique();
            $table->decimal('max_score', 6, 2)->default(20);
            $table->timestamp('created_at')->useCurrent();
        });

        Schema::create('grading_scale_bands', function (Blueprint $table) {
            $table->id();
            $table->foreignId('scale_id')->constrained('grading_scales')->cascadeOnDelete();
            $table->decimal('min_score', 6, 2);
            $table->decimal('max_score', 6, 2);
            $table->string('label');
            $table->boolean('is_passing')->default(true);
        });

        Schema::create('grading_policies', function (Blueprint $table) {
            $table->id();
            $table->foreignId('academic_year_id')->constrained()->cascadeOnDelete();
            $table->foreignId('level_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignId('scale_id')->constrained('grading_scales');
            $table->string('name')->default('Default');
            $table->decimal('pass_mark', 6, 2)->default(10);
            $table->smallInteger('rounding_decimals')->default(2);
            $table->string('rounding_mode', 10)->default('half_up');
            $table->boolean('ranking_enabled')->default(false);
            $table->json('term_weights')->nullable();
            $table->json('promotion_rules')->nullable();
            $table->string('absence_handling', 20)->default('ignore');
            $table->string('missing_grade_handling', 20)->default('ignore');
            $table->string('averaging_method', 20)->default('weighted');
            $table->json('averaging_params')->nullable();
            $table->string('subject_aggregation', 30)->default('coefficient_weighted');
            $table->string('term_aggregation', 20)->default('weighted_terms');
            $table->decimal('eliminatory_mark', 6, 2)->nullable();
            $table->smallInteger('min_graded_assessments')->default(1);
            $table->string('ranking_scope', 10)->default('class');
            $table->string('ranking_ties', 30)->default('shared');
            $table->boolean('show_rank_on_report_card')->default(false);
            $table->decimal('support_threshold', 6, 2)->nullable();
            $table->integer('version')->default(1);
            $table->boolean('is_active')->default(true);
            $table->timestamp('locked_at')->nullable();
            $table->string('locked_reason')->nullable();
            $table->timestamps();
        });

        Schema::create('assessment_types', function (Blueprint $table) {
            $table->id();
            $table->string('code')->unique();
            $table->string('name');
            $table->decimal('default_weight', 5, 2)->default(1);
        });

        Schema::create('assessments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('class_subject_teacher_id')->constrained('class_subject_teachers');
            $table->foreignId('term_id')->constrained();
            $table->foreignId('assessment_type_id')->constrained('assessment_types');
            $table->string('title');
            $table->date('assessment_date')->nullable();
            $table->decimal('max_score', 6, 2)->default(20);
            $table->decimal('weight', 5, 2)->default(1);
            $table->string('status', 20)->default('open');
            $table->foreignId('created_by')->constrained('users');
            $table->timestamps();
            $table->softDeletes();
            $table->index(['class_subject_teacher_id', 'term_id']);
            $table->index(['term_id', 'status']);
        });

        Schema::create('grades', function (Blueprint $table) {
            $table->id();
            $table->foreignId('assessment_id')->constrained()->cascadeOnDelete();
            $table->foreignId('student_id')->constrained()->cascadeOnDelete();
            $table->decimal('score', 6, 2)->nullable();
            $table->string('status', 20)->default('graded');
            $table->text('comment')->nullable();
            $table->foreignId('entered_by')->constrained('users');
            $table->uuid('client_uuid')->nullable()->unique();
            $table->timestamps();
            $table->unique(['assessment_id', 'student_id']);
            $table->index('student_id');
        });

        Schema::create('grade_history', function (Blueprint $table) {
            $table->id();
            $table->foreignId('grade_id')->constrained()->cascadeOnDelete();
            $table->decimal('old_score', 6, 2)->nullable();
            $table->decimal('new_score', 6, 2)->nullable();
            $table->string('old_status', 20)->nullable();
            $table->string('new_status', 20)->nullable();
            $table->foreignId('changed_by')->constrained('users');
            $table->text('reason')->nullable();
            $table->timestamp('changed_at')->useCurrent();
            $table->index(['grade_id', 'changed_at']);
        });

        Schema::create('grade_validation_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('class_id')->constrained('classes');
            $table->foreignId('subject_id')->constrained();
            $table->foreignId('term_id')->constrained();
            $table->string('action', 20);
            $table->foreignId('performed_by')->constrained('users');
            $table->text('reason')->nullable();
            $table->timestamp('created_at')->useCurrent();
            $table->index(['class_id', 'subject_id', 'term_id']);
        });

        Schema::create('exam_sessions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('academic_year_id')->constrained()->cascadeOnDelete();
            $table->foreignId('term_id')->nullable()->constrained()->nullOnDelete();
            $table->string('name');
            $table->string('exam_type', 20)->default('term');
            $table->date('start_date');
            $table->date('end_date');
            $table->string('status', 30)->default('planned');
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('exam_schedules', function (Blueprint $table) {
            $table->id();
            $table->foreignId('exam_session_id')->constrained('exam_sessions')->cascadeOnDelete();
            $table->foreignId('class_id')->constrained('classes');
            $table->foreignId('subject_id')->constrained();
            $table->foreignId('room_id')->nullable()->constrained()->nullOnDelete();
            $table->date('exam_date');
            $table->time('start_time');
            $table->time('end_time');
            $table->decimal('max_score', 6, 2)->default(20);
            $table->foreignId('assessment_id')->nullable()->unique()->constrained()->nullOnDelete();
            $table->unique(['exam_session_id', 'class_id', 'subject_id']);
            $table->index(['class_id', 'exam_date']);
            $table->index(['room_id', 'exam_date']);
        });

        Schema::create('exam_invigilators', function (Blueprint $table) {
            $table->id();
            $table->foreignId('exam_schedule_id')->constrained()->cascadeOnDelete();
            $table->foreignId('staff_id')->constrained();
            $table->string('role', 20)->default('invigilator');
            $table->unique(['exam_schedule_id', 'staff_id']);
        });

        Schema::create('exam_candidates', function (Blueprint $table) {
            $table->id();
            $table->foreignId('exam_schedule_id')->constrained()->cascadeOnDelete();
            $table->foreignId('student_id')->constrained();
            $table->string('seat_number')->nullable();
            $table->string('attendance', 20)->default('expected');
            $table->text('remarks')->nullable();
            $table->unique(['exam_schedule_id', 'student_id']);
        });

        Schema::create('subject_term_results', function (Blueprint $table) {
            $table->id();
            $table->foreignId('student_id')->constrained()->cascadeOnDelete();
            $table->foreignId('class_id')->constrained('classes');
            $table->foreignId('subject_id')->constrained();
            $table->foreignId('term_id')->constrained();
            $table->decimal('average', 6, 2)->nullable();
            $table->decimal('coefficient', 4, 2);
            $table->decimal('class_average', 6, 2)->nullable();
            $table->decimal('class_min', 6, 2)->nullable();
            $table->decimal('class_max', 6, 2)->nullable();
            $table->integer('rank')->nullable();
            $table->string('appreciation')->nullable();
            $table->foreignId('policy_id')->nullable()->constrained('grading_policies')->nullOnDelete();
            $table->timestamp('computed_at')->useCurrent();
            $table->unique(['student_id', 'subject_id', 'term_id']);
            $table->index(['class_id', 'term_id', 'subject_id']);
        });

        Schema::create('term_results', function (Blueprint $table) {
            $table->id();
            $table->foreignId('student_id')->constrained()->cascadeOnDelete();
            $table->foreignId('class_id')->constrained('classes');
            $table->foreignId('term_id')->constrained();
            $table->decimal('average', 6, 2)->nullable();
            $table->decimal('class_average', 6, 2)->nullable();
            $table->integer('rank')->nullable();
            $table->integer('class_size')->nullable();
            $table->integer('days_absent')->default(0);
            $table->integer('days_late')->default(0);
            $table->integer('days_excused')->default(0);
            $table->string('appreciation')->nullable();
            $table->boolean('needs_support')->default(false);
            $table->foreignId('policy_id')->nullable()->constrained('grading_policies')->nullOnDelete();
            $table->timestamp('computed_at')->useCurrent();
            $table->unique(['student_id', 'term_id']);
            $table->index(['class_id', 'term_id']);
        });

        Schema::create('annual_results', function (Blueprint $table) {
            $table->id();
            $table->foreignId('student_id')->constrained()->cascadeOnDelete();
            $table->foreignId('class_id')->constrained('classes');
            $table->foreignId('academic_year_id')->constrained();
            $table->decimal('average', 6, 2)->nullable();
            $table->integer('rank')->nullable();
            $table->string('decision', 20)->default('pending');
            $table->string('decision_note')->nullable();
            $table->foreignId('decided_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('decided_at')->nullable();
            $table->foreignId('policy_id')->nullable()->constrained('grading_policies')->nullOnDelete();
            $table->unsignedBigInteger('promotion_rule_id')->nullable(); // FK added after promotion_rules exists
            $table->string('decision_source', 10)->nullable();
            $table->unique(['student_id', 'academic_year_id']);
        });

        Schema::create('report_card_comments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('student_id')->constrained()->cascadeOnDelete();
            $table->foreignId('term_id')->constrained();
            $table->foreignId('subject_id')->nullable()->constrained()->cascadeOnDelete();
            $table->string('author_role', 30);
            $table->foreignId('author_id')->constrained('users');
            $table->text('comment');
            $table->timestamps();
            $table->index(['student_id', 'term_id']);
        });

        Schema::create('report_cards', function (Blueprint $table) {
            $table->id();
            $table->foreignId('student_id')->constrained()->cascadeOnDelete();
            $table->foreignId('class_id')->constrained('classes');
            $table->foreignId('academic_year_id')->constrained();
            $table->foreignId('term_id')->nullable()->constrained()->nullOnDelete();
            $table->integer('version')->default(1);
            $table->string('status', 20)->default('draft');
            $table->json('snapshot')->nullable();
            $table->foreignId('document_id')->nullable()->constrained('documents')->nullOnDelete();
            $table->foreignId('generated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('generated_at')->nullable();
            $table->foreignId('validated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('validated_at')->nullable();
            $table->timestamp('published_at')->nullable();
            $table->timestamp('created_at')->useCurrent();
            $table->unique(['student_id', 'academic_year_id', 'term_id', 'version'], 'uq_report_card_version');
        });

        Schema::create('assignments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('class_subject_teacher_id')->constrained('class_subject_teachers');
            $table->string('title');
            $table->text('instructions')->nullable();
            $table->timestamp('due_at');
            $table->boolean('allow_submission')->default(false);
            $table->boolean('allow_late_submission')->default(true);
            $table->foreignId('assessment_id')->nullable()->constrained()->nullOnDelete();
            $table->string('status', 20)->default('draft');
            $table->timestamp('published_at')->nullable();
            $table->foreignId('created_by')->constrained('users');
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('assignment_attachments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('assignment_id')->constrained()->cascadeOnDelete();
            $table->foreignId('document_id')->nullable()->constrained()->nullOnDelete();
            $table->string('link_url')->nullable();
            $table->string('label')->nullable();
        });

        Schema::create('assignment_submissions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('assignment_id')->constrained()->cascadeOnDelete();
            $table->foreignId('student_id')->constrained();
            $table->string('status', 20)->default('missing');
            $table->text('answer_text')->nullable();
            $table->timestamp('submitted_at')->nullable();
            $table->decimal('score', 6, 2)->nullable();
            $table->text('feedback')->nullable();
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->uuid('client_uuid')->nullable()->unique();
            $table->timestamps();
            $table->unique(['assignment_id', 'student_id']);
        });

        Schema::create('assignment_submission_files', function (Blueprint $table) {
            $table->id();
            $table->foreignId('submission_id')->constrained()->cascadeOnDelete();
            $table->foreignId('document_id')->constrained();
        });

        // ---- Phase 2b: generic typed settings registry -------------------
        Schema::create('setting_definitions', function (Blueprint $table) {
            $table->string('key')->primary();
            $table->string('module', 30);
            $table->string('label_key');
            $table->string('description_key')->nullable();
            $table->string('value_type', 20);
            $table->json('allowed_values')->nullable();
            $table->json('default_value');
            $table->json('allowed_scopes')->nullable();
            $table->string('requires_permission')->default('settings.manage');
        });

        Schema::create('settings', function (Blueprint $table) {
            $table->id();
            $table->string('key');
            $table->foreignId('campus_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignId('academic_year_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignId('level_id')->nullable()->constrained()->cascadeOnDelete();
            $table->json('value');
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('updated_at')->useCurrent();
            $table->foreign('key')->references('key')->on('setting_definitions')->cascadeOnDelete();
            $table->unique(['key', 'campus_id', 'academic_year_id', 'level_id'], 'uq_setting_scope');
        });

        Schema::create('grading_policy_subject_rules', function (Blueprint $table) {
            $table->id();
            $table->foreignId('policy_id')->constrained('grading_policies')->cascadeOnDelete();
            $table->foreignId('subject_id')->constrained();
            $table->foreignId('scale_id')->nullable()->constrained('grading_scales')->nullOnDelete();
            $table->decimal('pass_mark', 6, 2)->nullable();
            $table->decimal('eliminatory_mark', 6, 2)->nullable();
            $table->boolean('counts_in_average')->default(true);
            $table->decimal('bonus_above', 6, 2)->nullable();
            $table->boolean('is_core')->default(false);
            $table->unique(['policy_id', 'subject_id']);
        });

        Schema::create('rule_metrics', function (Blueprint $table) {
            $table->string('code')->primary();
            $table->string('label_key');
            $table->string('value_type', 10);
            $table->json('params_schema')->nullable();
        });

        Schema::create('promotion_rule_sets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('policy_id')->constrained('grading_policies')->cascadeOnDelete();
            $table->string('name');
            $table->text('description')->nullable();
            $table->timestamp('created_at')->useCurrent();
            $table->unique(['policy_id', 'name']);
        });

        Schema::create('promotion_rules', function (Blueprint $table) {
            $table->id();
            $table->foreignId('rule_set_id')->constrained('promotion_rule_sets')->cascadeOnDelete();
            $table->smallInteger('priority');
            $table->string('label');
            $table->json('condition');
            $table->string('outcome', 20);
            $table->boolean('requires_human_confirmation')->default(true);
            $table->boolean('is_active')->default(true);
            $table->unique(['rule_set_id', 'priority']);
        });

        Schema::create('promotion_rule_set_levels', function (Blueprint $table) {
            $table->id();
            $table->foreignId('rule_set_id')->constrained('promotion_rule_sets')->cascadeOnDelete();
            $table->foreignId('level_id')->nullable()->constrained()->cascadeOnDelete();
        });

        Schema::table('annual_results', function (Blueprint $table) {
            $table->foreign('promotion_rule_id')->references('id')->on('promotion_rules')->nullOnDelete();
        });
    }

    public function down(): void
    {
        foreach ([
            'promotion_rule_set_levels', 'promotion_rules', 'promotion_rule_sets', 'rule_metrics',
            'grading_policy_subject_rules', 'settings', 'setting_definitions',
            'assignment_submission_files', 'assignment_submissions', 'assignment_attachments', 'assignments',
            'report_cards', 'report_card_comments', 'annual_results', 'term_results', 'subject_term_results',
            'exam_candidates', 'exam_invigilators', 'exam_schedules', 'exam_sessions',
            'grade_validation_events', 'grade_history', 'grades', 'assessments', 'assessment_types',
            'grading_policies', 'grading_scale_bands', 'grading_scales',
        ] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
