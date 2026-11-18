<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 3: fees, billing, payments (append-only ledger), receipts, refunds,
 * reminders, expenses, cash closing. Mirrors phase3_schema.sql.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('number_series', function (Blueprint $table) {
            $table->string('code')->primary();
            $table->string('pattern');
            $table->boolean('is_active')->default(true);
            $table->timestamp('updated_at')->useCurrent();
        });

        Schema::create('number_series_counters', function (Blueprint $table) {
            $table->string('series_code');
            $table->string('period_key');
            $table->bigInteger('last_value')->default(0);
            $table->primary(['series_code', 'period_key']);
            $table->foreign('series_code')->references('code')->on('number_series')->cascadeOnDelete();
        });

        Schema::create('student_categories', function (Blueprint $table) {
            $table->id();
            $table->string('code')->unique();
            $table->string('name');
            $table->boolean('is_active')->default(true);
        });

        Schema::table('students', function (Blueprint $table) {
            $table->foreignId('student_category_id')->nullable()->constrained('student_categories')->nullOnDelete();
        });

        Schema::create('fee_categories', function (Blueprint $table) {
            $table->id();
            $table->string('code')->unique();
            $table->string('name');
            $table->string('kind', 20)->default('custom');
            $table->smallInteger('allocation_priority')->default(100);
            $table->boolean('is_refundable')->default(false);
            $table->boolean('is_active')->default(true);
        });

        Schema::create('installment_plans', function (Blueprint $table) {
            $table->id();
            $table->foreignId('academic_year_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->boolean('is_active')->default(true);
            $table->unique(['academic_year_id', 'name']);
        });

        Schema::create('installment_plan_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('plan_id')->constrained('installment_plans')->cascadeOnDelete();
            $table->smallInteger('sequence');
            $table->string('label')->nullable();
            $table->decimal('percentage', 5, 2);
            $table->date('due_date');
            $table->unique(['plan_id', 'sequence']);
        });

        Schema::create('fee_structures', function (Blueprint $table) {
            $table->id();
            $table->foreignId('academic_year_id')->constrained();
            $table->foreignId('fee_category_id')->constrained('fee_categories');
            $table->foreignId('level_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('track_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('student_category_id')->nullable()->constrained('student_categories')->nullOnDelete();
            $table->string('name')->nullable();
            $table->bigInteger('amount');
            $table->foreignId('installment_plan_id')->nullable()->constrained('installment_plans')->nullOnDelete();
            $table->date('due_date')->nullable();
            $table->boolean('is_mandatory')->default(true);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('discounts', function (Blueprint $table) {
            $table->id();
            $table->string('code')->unique();
            $table->string('name');
            $table->string('kind', 20)->default('discount');
            $table->decimal('value_percent', 5, 2)->nullable();
            $table->bigInteger('value_fixed')->nullable();
            $table->foreignId('fee_category_id')->nullable()->constrained('fee_categories')->nullOnDelete();
            $table->json('eligibility')->nullable();
            $table->boolean('requires_approval')->default(true);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('student_discounts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('student_id')->constrained();
            $table->foreignId('discount_id')->constrained('discounts');
            $table->foreignId('academic_year_id')->constrained();
            $table->string('status', 20)->default('pending');
            $table->foreignId('supporting_document_id')->nullable()->constrained('documents')->nullOnDelete();
            $table->text('note')->nullable();
            $table->foreignId('requested_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->timestamp('created_at')->useCurrent();
            $table->unique(['student_id', 'discount_id', 'academic_year_id'], 'uq_student_discount');
        });

        Schema::create('penalty_rules', function (Blueprint $table) {
            $table->id();
            $table->string('code')->unique();
            $table->string('name');
            $table->string('calc_type', 30);
            $table->bigInteger('value_fixed')->nullable();
            $table->decimal('value_percent', 5, 2)->nullable();
            $table->smallInteger('period_days')->nullable();
            $table->smallInteger('grace_days')->default(0);
            $table->bigInteger('max_total')->nullable();
            $table->foreignId('fee_category_id')->nullable()->constrained('fee_categories')->nullOnDelete();
            $table->string('application', 20)->default('manual');
            $table->boolean('is_active')->default(false);
        });

        Schema::create('payment_methods', function (Blueprint $table) {
            $table->id();
            $table->string('code')->unique();
            $table->string('name');
            $table->boolean('requires_reference')->default(false);
            $table->boolean('requires_proof')->default(false);
            $table->boolean('requires_verification')->default(false);
            $table->string('provider')->nullable();
            $table->boolean('is_active')->default(true);
            $table->smallInteger('sort_order')->default(0);
        });

        Schema::create('fee_generation_runs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('academic_year_id')->constrained();
            $table->json('scope')->nullable();
            $table->string('status', 20)->default('preview');
            $table->integer('charges_created')->default(0);
            $table->foreignId('run_by')->constrained('users');
            $table->timestamp('created_at')->useCurrent();
            $table->timestamp('committed_at')->nullable();
        });

        Schema::create('invoices', function (Blueprint $table) {
            $table->id();
            $table->string('invoice_number')->unique();
            $table->foreignId('student_id')->constrained();
            $table->foreignId('academic_year_id')->constrained();
            $table->date('issue_date');
            $table->date('due_date')->nullable();
            $table->string('status', 20)->default('issued');
            $table->foreignId('document_id')->nullable()->constrained('documents')->nullOnDelete();
            $table->text('notes')->nullable();
            $table->foreignId('issued_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('cancelled_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('cancelled_reason')->nullable();
            $table->timestamp('created_at')->useCurrent();
            $table->index(['student_id', 'academic_year_id']);
        });

        Schema::create('charges', function (Blueprint $table) {
            $table->id();
            $table->foreignId('student_id')->constrained();
            $table->foreignId('academic_year_id')->constrained();
            $table->foreignId('fee_category_id')->constrained('fee_categories');
            $table->foreignId('fee_structure_id')->nullable()->constrained('fee_structures')->nullOnDelete();
            $table->foreignId('invoice_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('generation_run_id')->nullable()->constrained('fee_generation_runs')->nullOnDelete();
            $table->string('description');
            $table->bigInteger('amount');
            $table->date('due_date');
            $table->smallInteger('installment_sequence')->nullable();
            $table->string('source', 20)->default('fee_structure');
            $table->string('status', 20)->default('open');
            $table->foreignId('cancelled_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('cancelled_reason')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('created_at')->useCurrent();
            $table->index(['student_id', 'academic_year_id']);
            $table->index('due_date');
        });
        // Re-running generation can never duplicate a student's fee installment.
        \Illuminate\Support\Facades\DB::statement(
            'CREATE UNIQUE INDEX uq_charge_generation ON charges (student_id, academic_year_id, fee_structure_id, installment_sequence) '
            .'WHERE fee_structure_id IS NOT NULL AND status = \'open\''
        );

        Schema::create('charge_adjustments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('charge_id')->constrained()->cascadeOnDelete();
            $table->string('kind', 20);
            $table->bigInteger('amount');
            $table->foreignId('student_discount_id')->nullable()->constrained('student_discounts')->nullOnDelete();
            $table->foreignId('penalty_rule_id')->nullable()->constrained('penalty_rules')->nullOnDelete();
            $table->string('period_key')->nullable();
            $table->text('reason')->nullable();
            $table->string('status', 20)->default('pending');
            $table->foreignId('requested_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->timestamp('created_at')->useCurrent();
            $table->index('charge_id');
        });
        \Illuminate\Support\Facades\DB::statement(
            'CREATE UNIQUE INDEX uq_penalty_once ON charge_adjustments (charge_id, penalty_rule_id, period_key) '
            .'WHERE kind = \'penalty\' AND status <> \'rejected\''
        );
        \Illuminate\Support\Facades\DB::statement(
            'CREATE UNIQUE INDEX uq_discount_once ON charge_adjustments (charge_id, student_discount_id) '
            .'WHERE student_discount_id IS NOT NULL AND status <> \'rejected\''
        );

        Schema::create('cash_closings', function (Blueprint $table) {
            $table->id();
            $table->date('closing_date');
            $table->foreignId('cashier_user_id')->constrained('users');
            $table->bigInteger('expected_amount')->default(0);
            $table->bigInteger('counted_amount')->nullable();
            $table->bigInteger('difference')->nullable();
            $table->string('status', 20)->default('open');
            $table->text('note')->nullable();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('closed_at')->nullable();
            $table->unique(['closing_date', 'cashier_user_id']);
        });

        Schema::create('payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('academic_year_id')->constrained();
            $table->foreignId('payer_guardian_id')->nullable()->constrained('guardians')->nullOnDelete();
            $table->string('payer_name')->nullable();
            $table->foreignId('payment_method_id')->constrained('payment_methods');
            $table->bigInteger('amount');
            $table->timestamp('paid_at');
            $table->string('reference')->nullable();
            $table->foreignId('proof_document_id')->nullable()->constrained('documents')->nullOnDelete();
            $table->string('status', 20)->default('pending');
            $table->foreignId('received_by')->constrained('users');
            $table->foreignId('verified_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('verified_at')->nullable();
            $table->string('rejected_reason')->nullable();
            $table->foreignId('cash_closing_id')->nullable()->constrained()->nullOnDelete();
            $table->text('notes')->nullable();
            $table->uuid('client_uuid')->nullable()->unique();
            $table->timestamps();
            $table->index(['status', 'paid_at']);
            $table->index('payer_guardian_id');
        });
        \Illuminate\Support\Facades\DB::statement(
            'CREATE UNIQUE INDEX uq_payment_reference ON payments (payment_method_id, reference) '
            .'WHERE reference IS NOT NULL AND status <> \'rejected\''
        );

        Schema::create('payment_allocations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('payment_id')->constrained()->cascadeOnDelete();
            $table->foreignId('charge_id')->constrained('charges');
            $table->bigInteger('amount');
            $table->foreignId('reversal_of_id')->nullable()->constrained('payment_allocations')->nullOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('created_at')->useCurrent();
            $table->index('payment_id');
            $table->index('charge_id');
        });

        Schema::create('payment_reversals', function (Blueprint $table) {
            $table->id();
            $table->foreignId('payment_id')->constrained();
            $table->string('kind', 20);
            $table->bigInteger('amount');
            $table->text('reason');
            $table->string('status', 20)->default('requested');
            $table->foreignId('requested_by')->constrained('users');
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->foreignId('refund_method_id')->nullable()->constrained('payment_methods')->nullOnDelete();
            $table->string('refund_reference')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('created_at')->useCurrent();
            $table->index('payment_id');
        });

        Schema::create('receipts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('payment_id')->unique()->constrained()->cascadeOnDelete();
            $table->string('receipt_number')->unique();
            $table->timestamp('issued_at')->useCurrent();
            $table->foreignId('issued_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('document_id')->nullable()->constrained('documents')->nullOnDelete();
            $table->string('status', 20)->default('issued');
            $table->foreignId('voided_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('voided_reason')->nullable();
            $table->timestamp('voided_at')->nullable();
        });

        Schema::create('reminder_schedules', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('trigger_basis', 20);
            $table->smallInteger('offset_days');
            $table->smallInteger('repeat_every_days')->nullable();
            $table->smallInteger('max_repeats')->default(1);
            $table->json('channels')->nullable();
            $table->string('template_key');
            $table->bigInteger('min_outstanding')->default(1);
            $table->foreignId('level_id')->nullable()->constrained()->nullOnDelete();
            $table->boolean('is_active')->default(true);
        });

        Schema::create('reminder_log', function (Blueprint $table) {
            $table->id();
            $table->foreignId('schedule_id')->constrained('reminder_schedules')->cascadeOnDelete();
            $table->foreignId('charge_id')->constrained('charges')->cascadeOnDelete();
            $table->foreignId('guardian_id')->constrained('guardians')->cascadeOnDelete();
            $table->string('channel', 20);
            $table->smallInteger('occurrence')->default(1);
            $table->string('status', 20)->default('queued');
            $table->text('error')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->timestamp('created_at')->useCurrent();
            $table->unique(['schedule_id', 'charge_id', 'guardian_id', 'channel', 'occurrence'], 'uq_reminder_once');
        });

        Schema::create('expense_categories', function (Blueprint $table) {
            $table->id();
            $table->string('code')->unique();
            $table->string('name');
            $table->boolean('is_active')->default(true);
        });

        Schema::create('expenses', function (Blueprint $table) {
            $table->id();
            $table->foreignId('academic_year_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('category_id')->constrained('expense_categories');
            $table->string('supplier')->nullable();
            $table->string('description');
            $table->bigInteger('amount');
            $table->date('expense_date');
            $table->foreignId('payment_method_id')->nullable()->constrained('payment_methods')->nullOnDelete();
            $table->string('reference')->nullable();
            $table->foreignId('document_id')->nullable()->constrained('documents')->nullOnDelete();
            $table->string('status', 20)->default('requested');
            $table->foreignId('requested_by')->constrained('users');
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('paid_at')->nullable();
            $table->timestamp('created_at')->useCurrent();
            $table->index(['expense_date', 'status']);
        });
    }

    public function down(): void
    {
        foreach ([
            'expenses', 'expense_categories', 'reminder_log', 'reminder_schedules', 'receipts',
            'payment_reversals', 'payment_allocations', 'payments', 'cash_closings', 'charge_adjustments',
            'charges', 'invoices', 'fee_generation_runs', 'payment_methods', 'penalty_rules',
            'student_discounts', 'discounts', 'fee_structures', 'installment_plan_items', 'installment_plans',
            'fee_categories', 'number_series_counters', 'number_series',
        ] as $table) {
            Schema::dropIfExists($table);
        }
        Schema::table('students', function (Blueprint $table) {
            $table->dropForeign(['student_category_id']);
            $table->dropColumn('student_category_id');
        });
        Schema::dropIfExists('student_categories');
    }
};
