<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Guardian extends Model
{
    use SoftDeletes;

    protected $guarded = [];

    public function students(): BelongsToMany
    {
        return $this->belongsToMany(Student::class, 'student_guardians')
            ->withPivot([
                'id', 'relationship', 'is_primary_contact', 'is_emergency_contact',
                'can_view_academic', 'can_view_finance', 'verified_at', 'revoked_at',
            ])
            ->withTimestamps();
    }

    /** Links the guardian may use in the portal (verified, not revoked). */
    public function activeLinks()
    {
        return StudentGuardian::query()
            ->where('guardian_id', $this->id)
            ->whereNotNull('verified_at')
            ->whereNull('revoked_at');
    }
}
