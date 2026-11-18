<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ChargeAdjustment extends Model
{
    public $timestamps = false;

    protected $guarded = [];

    protected $casts = [
        'amount' => 'integer',
        'approved_at' => 'datetime',
        'created_at' => 'datetime',
    ];

    public function charge(): BelongsTo
    {
        return $this->belongsTo(Charge::class);
    }

    public function approvedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function requestedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    public function studentDiscount(): BelongsTo
    {
        return $this->belongsTo(StudentDiscount::class);
    }
}
