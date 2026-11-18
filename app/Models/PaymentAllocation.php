<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PaymentAllocation extends Model
{
    public $timestamps = false;

    protected $guarded = [];

    protected $casts = [
        'amount' => 'integer',
        'created_at' => 'datetime',
    ];

    public function payment(): BelongsTo
    {
        return $this->belongsTo(Payment::class);
    }

    public function charge(): BelongsTo
    {
        return $this->belongsTo(Charge::class);
    }

    public function reversedBy(): BelongsTo
    {
        return $this->belongsTo(self::class, 'reversal_of_id');
    }
}
