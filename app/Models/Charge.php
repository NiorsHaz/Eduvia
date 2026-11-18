<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\DB;

class Charge extends Model
{
    public $timestamps = false;

    protected $guarded = [];

    protected $casts = [
        'amount' => 'integer',
        'due_date' => 'date',
        'created_at' => 'datetime',
    ];

    public function student(): BelongsTo
    {
        return $this->belongsTo(Student::class);
    }

    public function feeCategory(): BelongsTo
    {
        return $this->belongsTo(FeeCategory::class);
    }

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }

    public function adjustments(): HasMany
    {
        return $this->hasMany(ChargeAdjustment::class);
    }

    public function allocations(): HasMany
    {
        return $this->hasMany(PaymentAllocation::class);
    }

    /** Balances are derived, never stored (phase3 v_charge_balances). */
    public function balances(): array
    {
        $approved = (int) $this->adjustments()->where('status', 'approved')->sum('amount');
        $paid = (int) $this->allocations()->sum('amount');
        $netDue = (int) $this->amount + $approved;

        return [
            'gross_amount' => (int) $this->amount,
            'adjustments_total' => $approved,
            'net_due' => $netDue,
            'paid' => $paid,
            'outstanding' => $netDue - $paid,
            'is_overdue' => $netDue - $paid > 0 && $this->due_date?->isPast(),
        ];
    }

    public function scopeOpen($query)
    {
        return $query->where('status', 'open');
    }

    public function netDue(): int
    {
        return (int) $this->amount + (int) $this->adjustments()->where('status', 'approved')->sum('amount');
    }

    public function paidTotal(): int
    {
        return (int) $this->allocations()->sum('amount');
    }

    public static function balancesForStudent(int $studentId, int $yearId): array
    {
        $charges = static::where('student_id', $studentId)->where('academic_year_id', $yearId)->where('status', 'open')->get();

        $rows = $charges->map(fn (self $c) => $c->balances());

        return [
            'total_due' => $rows->sum('net_due'),
            'total_paid' => $rows->sum('paid'),
            'total_outstanding' => $rows->sum('outstanding'),
            'overdue_amount' => $rows->where('is_overdue', true)->sum('outstanding'),
        ];
    }

    public static function nextSequence(int $studentId, int $feeStructureId): int
    {
        return (int) DB::table('charges')
            ->where('student_id', $studentId)
            ->where('fee_structure_id', $feeStructureId)
            ->max('installment_sequence') + 1;
    }
}
