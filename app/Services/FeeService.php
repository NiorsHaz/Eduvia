<?php

namespace App\Services;

use App\Models\Charge;
use App\Models\ChargeAdjustment;
use App\Models\Classroom;
use App\Models\Discount;
use App\Models\FeeGenerationRun;
use App\Models\FeeStructure;
use App\Models\InstallmentPlanItem;
use App\Models\Invoice;
use App\Models\PenaltyRule;
use App\Models\Student;
use App\Models\StudentDiscount;
use Illuminate\Support\Facades\DB;

/**
 * Repeatable fee generation. Re-running can never duplicate a student's
 * installment: the unique index uq_charge_generation is the final guard.
 */
class FeeService
{
    public function __construct(private readonly NumberSeriesService $numbers) {}

    /**
     * @param  array{level_ids?:array, class_ids?:array, include_optional?:bool}  $scope
     * @return array{run:FeeGenerationRun, created:int, skipped:int, students:int}
     */
    public function generate(int $academicYearId, array $scope, int $userId, bool $commit = true): array
    {
        return DB::transaction(function () use ($academicYearId, $scope, $userId, $commit) {
            $run = FeeGenerationRun::create([
                'academic_year_id' => $academicYearId,
                'scope' => $scope,
                'status' => $commit ? 'committed' : 'preview',
                'run_by' => $userId,
                'committed_at' => $commit ? now() : null,
            ]);

            $structures = FeeStructure::query()
                ->where('academic_year_id', $academicYearId)
                ->where('is_active', true)
                ->when(empty($scope['include_optional']), fn ($q) => $q->where('is_mandatory', true))
                ->get();

            $classes = Classroom::query()
                ->where('academic_year_id', $academicYearId)
                ->when(! empty($scope['level_ids']), fn ($q) => $q->whereIn('level_id', $scope['level_ids']))
                ->when(! empty($scope['class_ids']), fn ($q) => $q->whereIn('id', $scope['class_ids']))
                ->get();

            $created = 0;
            $skipped = 0;
            $studentsTouched = 0;

            foreach ($classes as $class) {
                $students = $class->students()->whereNull('students.deleted_at')->get();

                foreach ($students as $student) {
                    $studentCreated = 0;

                    foreach ($structures as $structure) {
                        if (! $this->structureMatches($structure, $class, $student)) {
                            continue;
                        }

                        $invoice = null;
                        $installments = $this->installmentsFor($structure);

                        foreach ($installments as $installment) {
                            $exists = Charge::where('student_id', $student->id)
                                ->where('academic_year_id', $academicYearId)
                                ->where('fee_structure_id', $structure->id)
                                ->where('installment_sequence', $installment['sequence'])
                                ->where('status', 'open')
                                ->exists();

                            if ($exists) {
                                $skipped++;

                                continue;
                            }

                            $invoice ??= $this->invoiceFor($student, $academicYearId, $userId, $installment['due_date']);

                            Charge::create([
                                'student_id' => $student->id,
                                'academic_year_id' => $academicYearId,
                                'fee_category_id' => $structure->fee_category_id,
                                'fee_structure_id' => $structure->id,
                                'invoice_id' => $invoice?->id,
                                'generation_run_id' => $run->id,
                                'description' => trim(($structure->category?->name ?? 'Fee').' '.($installment['label'] ? '- '.$installment['label'] : '')),
                                'amount' => $installment['amount'],
                                'due_date' => $installment['due_date'],
                                'installment_sequence' => $installment['sequence'],
                                'source' => 'fee_structure',
                                'status' => 'open',
                                'created_by' => $userId,
                                'created_at' => now(),
                            ]);

                            $created++;
                            $studentCreated++;
                        }
                    }

                    if ($studentCreated > 0) {
                        $studentsTouched++;
                    }
                }
            }

            $run->update(['charges_created' => $created]);

            if (! $commit) {
                // Preview runs never keep their charges.
                Charge::where('generation_run_id', $run->id)->delete();
                $run->update(['charges_created' => 0]);
            }

            return ['run' => $run, 'created' => $commit ? $created : $created, 'skipped' => $skipped, 'students' => $studentsTouched];
        });
    }

    /** Fixed, per-period, percentage and capped penalties from penalty_rules. */
    public function applyPenalties(Charge $charge, int $userId): int
    {
        $rules = PenaltyRule::where('is_active', true)
            ->where(fn ($q) => $q->whereNull('fee_category_id')->orWhere('fee_category_id', $charge->fee_category_id))
            ->get();

        if ($charge->due_date === null || ! $charge->due_date->isPast()) {
            return 0;
        }

        $applied = 0;

        foreach ($rules as $rule) {
            $due = $charge->due_date->copy()->addDays((int) $rule->grace_days);
            if ($due->isFuture()) {
                continue;
            }

            $overdueDays = max(1, (int) $due->diffInDays(now()));
            $periods = $rule->period_days ? max(1, (int) ceil($overdueDays / (int) $rule->period_days)) : 1;
            $outstanding = max(0, $charge->netDue() - $charge->paidTotal());

            $amount = match ($rule->calc_type) {
                'fixed' => (int) $rule->value_fixed,
                'fixed_per_period' => (int) $rule->value_fixed * $periods,
                'percent_of_outstanding' => (int) round($outstanding * (float) $rule->value_percent / 100),
                'percent_per_period' => (int) round($outstanding * (float) $rule->value_percent / 100) * $periods,
                default => 0,
            };

            if ($rule->max_total) {
                $amount = min($amount, (int) $rule->max_total);
            }

            if ($amount <= 0) {
                continue;
            }

            $exists = ChargeAdjustment::where('charge_id', $charge->id)
                ->where('penalty_rule_id', $rule->id)
                ->where('kind', 'penalty')
                ->where('status', '<>', 'rejected')
                ->exists();

            if ($exists) {
                continue;
            }

            ChargeAdjustment::create([
                'charge_id' => $charge->id,
                'kind' => 'penalty',
                'amount' => $amount,
                'penalty_rule_id' => $rule->id,
                'period_key' => now()->format('Y-m'),
                'reason' => 'Automatic penalty: '.$rule->name,
                'status' => $rule->application === 'automatic' ? 'approved' : 'pending',
                'requested_by' => $userId,
                'approved_by' => $rule->application === 'automatic' ? $userId : null,
                'approved_at' => $rule->application === 'automatic' ? now() : null,
                'created_at' => now(),
            ]);

            $applied++;
        }

        return $applied;
    }

    /**
     * Materialise approved scholarships/discounts as negative adjustments.
     * Idempotent through uq_discount_once.
     */
    public function applyApprovedDiscounts(int $studentId, int $academicYearId): int
    {
        $grants = StudentDiscount::with('discount')
            ->where('student_id', $studentId)
            ->where('academic_year_id', $academicYearId)
            ->where('status', 'approved')
            ->get();

        $created = 0;

        foreach ($grants as $grant) {
            /** @var Discount $discount */
            $discount = $grant->discount;
            if (! $discount || ! $discount->is_active) {
                continue;
            }

            $charges = Charge::where('student_id', $studentId)
                ->where('academic_year_id', $academicYearId)
                ->where('status', 'open')
                ->when($discount->fee_category_id, fn ($q) => $q->where('fee_category_id', $discount->fee_category_id))
                ->get();

            foreach ($charges as $charge) {
                $amount = $discount->value_percent !== null
                    ? (int) round((int) $charge->amount * (float) $discount->value_percent / 100)
                    : (int) $discount->value_fixed;

                if ($amount <= 0) {
                    continue;
                }

                $exists = ChargeAdjustment::where('charge_id', $charge->id)
                    ->where('student_discount_id', $grant->id)
                    ->where('status', '<>', 'rejected')
                    ->exists();

                if ($exists) {
                    continue;
                }

                ChargeAdjustment::create([
                    'charge_id' => $charge->id,
                    'kind' => $discount->kind === 'scholarship' ? 'scholarship' : 'discount',
                    'amount' => -min($amount, (int) $charge->amount),
                    'student_discount_id' => $grant->id,
                    'reason' => $discount->name,
                    'status' => 'approved',
                    'approved_by' => $grant->approved_by,
                    'approved_at' => now(),
                    'created_at' => now(),
                ]);

                $created++;
            }
        }

        return $created;
    }

    private function structureMatches(FeeStructure $structure, Classroom $class, Student $student): bool
    {
        if ($structure->level_id !== null && (int) $structure->level_id !== (int) $class->level_id) {
            return false;
        }
        if ($structure->track_id !== null && (int) $structure->track_id !== (int) $class->track_id) {
            return false;
        }
        if ($structure->student_category_id !== null && (int) $structure->student_category_id !== (int) $student->student_category_id) {
            return false;
        }

        return true;
    }

    /** @return array<int, array{sequence:int, label:?string, amount:int, due_date:string}> */
    private function installmentsFor(FeeStructure $structure): array
    {
        $amount = (int) $structure->amount;

        if (! $structure->installment_plan_id) {
            return [[
                'sequence' => 1,
                'label' => null,
                'amount' => $amount,
                'due_date' => $structure->due_date?->toDateString() ?? now()->toDateString(),
            ]];
        }

        $items = InstallmentPlanItem::where('plan_id', $structure->installment_plan_id)->orderBy('sequence')->get();
        $rows = [];
        $allocated = 0;

        foreach ($items as $index => $item) {
            $isLast = $index === $items->count() - 1;
            $part = $isLast ? $amount - $allocated : (int) round($amount * (float) $item->percentage / 100);
            $allocated += $part;

            $rows[] = [
                'sequence' => (int) $item->sequence,
                'label' => $item->label,
                'amount' => $part,
                'due_date' => $item->due_date->toDateString(),
            ];
        }

        return $rows;
    }

    private function invoiceFor(Student $student, int $academicYearId, int $userId, string $dueDate): Invoice
    {
        $existing = Invoice::where('student_id', $student->id)
            ->where('academic_year_id', $academicYearId)
            ->where('status', 'issued')
            ->first();

        if ($existing) {
            return $existing;
        }

        $yearName = \App\Models\AcademicYear::find($academicYearId)?->name ?? (string) $academicYearId;

        return Invoice::create([
            'invoice_number' => $this->numbers->next('invoice', $yearName),
            'student_id' => $student->id,
            'academic_year_id' => $academicYearId,
            'issue_date' => now()->toDateString(),
            'due_date' => $dueDate,
            'status' => 'issued',
            'issued_by' => $userId,
            'created_at' => now(),
        ]);
    }
}
