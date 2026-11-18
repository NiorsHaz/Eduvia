<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StudentGuardian extends Model
{
    protected $guarded = [];

    protected $casts = [
        'is_primary_contact' => 'boolean',
        'is_emergency_contact' => 'boolean',
        'is_authorized_pickup' => 'boolean',
        'can_view_academic' => 'boolean',
        'can_view_finance' => 'boolean',
        'verified_at' => 'datetime',
        'revoked_at' => 'datetime',
    ];

    public function student(): BelongsTo
    {
        return $this->belongsTo(Student::class);
    }

    public function guardian(): BelongsTo
    {
        return $this->belongsTo(Guardian::class);
    }
}
