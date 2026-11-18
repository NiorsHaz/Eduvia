<?php

namespace App\Services;

use App\Models\Charge;
use App\Models\Payment;
use App\Models\PaymentAllocation;
use App\Models\PaymentMethod;
use App\Models\PaymentReversal;
use App\Models\Receipt;
use App\Models\StudentGuardian;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Append-only ledger: payments are never deleted or rewritten. Verification,
 * allocation and receipt issuance happen in one transaction so receipt
 * numbers stay gapless.
 */
class PaymentService
{
    public function __construct(
        private readonly NumberSeriesService $numbers,
        private readonly SettingService $settings,
    ) {}

    public function record(array $data, User $user): Payment
    {
        if (! empty($data['client_uuid'])) {
            $existing = Payment::where('client_uuid', $data['client_uuid'])->first();
            if ($existing) {
                return $existing->load('receipt');
            }
        }

        $method = PaymentMethod::findOrFail($data['payment_method_id']);

        if (($method->requires_reference || ! empty($data['reference'])) && ! empty($data['reference'])) {
            $duplicate = Payment::where('payment_method_id', $method->id)
                ->where('reference', $data['reference'])
                ->where('status', '<>', 'rejected')
                ->exists();

            if ($duplicate) {
                throw ValidationException::withMessages([
                    'reference' => 'This reference has already been recorded for '.$method->name.'.',
                ]);
            }
        }

        if ($method->requires_reference && empty($data['reference'])) {
            throw ValidationException::withMessages(['reference' => $method->name.' requires a reference.']);
        }

        $payment = Payment::create([
            'academic_year_id' => $data['academic_year_id'],
            'payer_guardian_id' => $data['payer_guardian_id'] ?? null,
            'payer_name' => $data['payer_name'] ?? 'Guardian',
            'payment_method_id' => $method->id,
            'amount' => (int) $data['amount'],
            'paid_at' => $data['paid_at'] ?? now(),
            'reference' => $data['reference'] ?? null,
            'status' => 'pending',
            'received_by' => $user->id,
            'notes' => $data['notes'] ?? null,
            'client_uuid' => $data['client_uuid'] ?? null,
        ]);

        if (! $method->requires_verification) {
            return $this->verify($payment, $user, $data['allocations'] ?? null, $data['charge_ids'] ?? []);
        }

        return $payment;
    }

    /**
     * @param  array<int, array{charge_id:int, amount:int}>|null  $allocations
     * @param  array<int, int>  $chargeIds  candidates for automatic allocation
     */
    public function verify(Payment $payment, User $user, ?array $allocations = null, array $chargeIds = []): Payment
    {
        return DB::transaction(function () use ($payment, $user, $allocations, $chargeIds) {
            $payment = Payment::whereKey($payment->id)->lockForUpdate()->firstOrFail();

            if ($payment->status !== 'pending') {
                return $payment->load('receipt');
            }

            $payment->update([
                'status' => 'verified',
                'verified_by' => $user->id,
                'verified_at' => now(),
            ]);

            $allocations ??= $this->autoAllocations($payment, $chargeIds);
            $this->allocate($payment->refresh(), $allocations, $user);

            $this->issueReceipt($payment->refresh(), $user);

            return $payment->fresh(['receipt', 'allocations']);
        });
    }

    /** @param array<int, array{charge_id:int, amount:int}> $allocations */
    public function allocate(Payment $payment, array $allocations, ?User $user = null): void
    {
        $remaining = (int) $payment->amount - (int) $payment->allocations()->sum('amount');

        foreach ($allocations as $row) {
            $amount = (int) $row['amount'];
            if ($amount <= 0) {
                continue;
            }

            if ($amount > $remaining) {
                throw ValidationException::withMessages([
                    'allocations' => 'Allocations cannot exceed the payment amount.',
                ]);
            }

            $charge = Charge::whereKey($row['charge_id'])->lockForUpdate()->firstOrFail();
            if ($charge->status !== 'open') {
                throw ValidationException::withMessages(['allocations' => 'Charge #'.$charge->id.' is not open.']);
            }

            $outstanding = $charge->netDue() - $charge->paidTotal();
            if ($amount > $outstanding) {
                throw ValidationException::withMessages([
                    'allocations' => 'Allocation exceeds the amount due on charge #'.$charge->id.'.',
                ]);
            }

            PaymentAllocation::create([
                'payment_id' => $payment->id,
                'charge_id' => $charge->id,
                'amount' => $amount,
                'created_by' => $user?->id,
                'created_at' => now(),
            ]);

            $remaining -= $amount;
        }
    }

    public function issueReceipt(Payment $payment, User $user): Receipt
    {
        if ($payment->receipt) {
            return $payment->receipt;
        }

        if ($payment->status !== 'verified') {
            throw ValidationException::withMessages(['payment' => 'Only verified payments can receive a receipt.']);
        }

        $yearName = $payment->academicYear?->name ?? (string) $payment->academic_year_id;

        return Receipt::create([
            'payment_id' => $payment->id,
            'receipt_number' => $this->numbers->next('receipt', $yearName),
            'issued_at' => now(),
            'issued_by' => $user->id,
            'status' => 'issued',
        ]);
    }

    public function requestReversal(Payment $payment, string $kind, int $amount, string $reason, User $user): PaymentReversal
    {
        if (! in_array($kind, ['reversal', 'refund'], true)) {
            throw ValidationException::withMessages(['kind' => 'Kind must be reversal or refund.']);
        }

        return PaymentReversal::create([
            'payment_id' => $payment->id,
            'kind' => $kind,
            'amount' => $amount,
            'reason' => $reason,
            'status' => 'requested',
            'requested_by' => $user->id,
            'refund_method_id' => $kind === 'refund' ? $payment->payment_method_id : null,
            'created_at' => now(),
        ]);
    }

    /** Four-eyes rule: approver must differ from requester. */
    public function approveReversal(PaymentReversal $reversal, User $user): PaymentReversal
    {
        if ((int) $reversal->requested_by === (int) $user->id) {
            throw ValidationException::withMessages([
                'approved_by' => 'A reversal or refund must be approved by a different person from the requester.',
            ]);
        }

        return DB::transaction(function () use ($reversal, $user) {
            $payment = Payment::whereKey($reversal->payment_id)->lockForUpdate()->firstOrFail();

            if ($payment->status !== 'verified') {
                throw ValidationException::withMessages(['payment' => 'Only verified payments can be reversed.']);
            }

            $allocations = $payment->allocations()->get();
            $toReverse = min((int) $reversal->amount, (int) $allocations->sum('amount'));

            foreach ($allocations as $allocation) {
                if ($toReverse <= 0) {
                    break;
                }
                $amount = min($toReverse, (int) $allocation->amount);
                if ($amount <= 0) {
                    continue;
                }

                PaymentAllocation::create([
                    'payment_id' => $payment->id,
                    'charge_id' => $allocation->charge_id,
                    'amount' => -$amount,
                    'reversal_of_id' => $allocation->id,
                    'created_by' => $user->id,
                    'created_at' => now(),
                ]);

                $toReverse -= $amount;
            }

            $reversal->update([
                'status' => 'completed',
                'approved_by' => $user->id,
                'approved_at' => now(),
                'completed_at' => now(),
            ]);

            if ($reversal->kind === 'reversal') {
                $payment->update(['status' => 'reversed']);
                $payment->receipt?->update([
                    'status' => 'voided',
                    'voided_by' => $user->id,
                    'voided_reason' => $reversal->reason,
                    'voided_at' => now(),
                ]);
            }

            activity('finance')->performedOn($payment)
                ->withProperties(['kind' => $reversal->kind, 'amount' => $reversal->amount])
                ->log('payment.reversal_approved');

            return $reversal->refresh();
        });
    }

    /** @return array<int, array{charge_id:int, amount:int}> */
    private function autoAllocations(Payment $payment, array $chargeIds): array
    {
        $yearId = $payment->academic_year_id;

        if (! $chargeIds && $payment->payer_guardian_id) {
            $studentIds = StudentGuardian::where('guardian_id', $payment->payer_guardian_id)
                ->whereNull('revoked_at')->whereNotNull('verified_at')->pluck('student_id');
            $chargeIds = Charge::whereIn('student_id', $studentIds)->where('academic_year_id', $yearId)
                ->where('status', 'open')->pluck('id')->all();
        }

        $strategy = $this->settings->get('finance.allocation_strategy') ?? 'oldest_due_first';

        $query = Charge::whereIn('id', $chargeIds)
            ->where('academic_year_id', $yearId)
            ->where('status', 'open');

        $query = match ($strategy) {
            'by_category_priority' => $query->orderBy(
                \App\Models\FeeCategory::select('allocation_priority')
                    ->whereColumn('fee_categories.id', 'charges.fee_category_id')
            )->orderBy('due_date'),
            'manual' => $query->orderBy('due_date'),
            default => $query->orderBy('due_date')->orderBy('id'),
        };

        $remaining = (int) $payment->amount;
        $rows = [];

        foreach ($query->get() as $charge) {
            if ($remaining <= 0) {
                break;
            }
            $outstanding = $charge->netDue() - $charge->paidTotal();
            if ($outstanding <= 0) {
                continue;
            }
            $amount = min($remaining, $outstanding);
            $rows[] = ['charge_id' => $charge->id, 'amount' => $amount];
            $remaining -= $amount;
        }

        return $rows;
    }
}
