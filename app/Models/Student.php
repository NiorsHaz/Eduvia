<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;

class Student extends Model
{
    use SoftDeletes;

    protected $guarded = [];

    protected $casts = [
        'date_of_birth' => 'date',
        'admission_date' => 'date',
        'exit_date' => 'date',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(StudentCategory::class, 'student_category_id');
    }

    public function healthRecord(): HasOne
    {
        return $this->hasOne(StudentHealthRecord::class);
    }

    public function guardians(): BelongsToMany
    {
        return $this->belongsToMany(Guardian::class, 'student_guardians')
            ->withPivot([
                'id', 'relationship', 'is_primary_contact', 'is_emergency_contact', 'is_authorized_pickup',
                'can_view_academic', 'can_view_finance', 'verified_at', 'revoked_at', 'revoke_reason',
            ])
            ->withTimestamps();
    }

    public function enrollments(): HasMany
    {
        return $this->hasMany(Enrollment::class);
    }

    public function currentEnrollment(): HasOne
    {
        return $this->hasOne(Enrollment::class)->whereNull('end_date')->where('status', 'active');
    }

    /** Portal access requires a verified, non-revoked guardian link. */
    public function hasVerifiedGuardian(?int $guardianId = null): bool
    {
        return $this->guardians()
            ->whereNotNull('student_guardians.verified_at')
            ->whereNull('student_guardians.revoked_at')
            ->when($guardianId, fn ($q) => $q->where('guardians.id', $guardianId))
            ->exists();
    }
}
