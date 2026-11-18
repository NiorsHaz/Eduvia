<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Payment extends Model
{
    protected $guarded = [];

    protected $casts = [
        'amount' => 'integer',
        'paid_at' => 'datetime',
        'verified_at' => 'datetime',
    ];

    public function method(): BelongsTo
    {
        return $this->belongsTo(PaymentMethod::class, 'payment_method_id');
    }

    public function academicYear(): BelongsTo
    {
        return $this->belongsTo(AcademicYear::class);
    }

    public function payerGuardian(): BelongsTo
    {
        return $this->belongsTo(Guardian::class, 'payer_guardian_id');
    }

    public function allocations(): HasMany
    {
        return $this->hasMany(PaymentAllocation::class);
    }

    public function receipt(): HasOne
    {
        return $this->hasOne(Receipt::class);
    }

    public function reversals(): HasMany
    {
        return $this->hasMany(PaymentReversal::class);
    }

    public function unallocated(): int
    {
        return (int) $this->amount - (int) $this->allocations()->sum('amount');
    }

    public function students()
    {
        return Student::query()
            ->whereIn('id', $this->allocations()->join('charges', 'charges.id', '=', 'payment_allocations.charge_id')->pluck('charges.student_id'))
            ->get();
    }
}
