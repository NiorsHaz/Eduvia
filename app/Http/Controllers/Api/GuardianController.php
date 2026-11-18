<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Guardian;
use App\Models\Student;
use App\Models\StudentGuardian;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class GuardianController extends Controller
{
    public function index(Request $request)
    {
        return Guardian::query()
            ->when($request->filled('q'), function ($q) use ($request) {
                $term = '%'.$request->string('q').'%';
                $q->where(fn ($w) => $w->where('first_name', 'like', $term)
                    ->orWhere('last_name', 'like', $term)
                    ->orWhere('phone', 'like', $term));
            })
            ->withCount(['students' => fn ($q) => $q->whereNull('student_guardians.revoked_at')])
            ->orderBy('last_name')->paginate(25);
    }

    public function store(Request $request)
    {
        $guardian = Guardian::create($request->validate($this->rules()));
        activity('guardians')->performedOn($guardian)->log('guardian.created');

        return response()->json($guardian, 201);
    }

    public function show(Guardian $guardian)
    {
        return $guardian->load('students');
    }

    public function update(Request $request, Guardian $guardian)
    {
        $guardian->update($request->validate($this->rules()));
        activity('guardians')->performedOn($guardian)->log('guardian.updated');

        return $guardian;
    }

    public function destroy(Guardian $guardian)
    {
        $guardian->delete();

        return response()->noContent();
    }

    public function link(Request $request, Student $student)
    {
        $data = $request->validate([
            'guardian_id' => 'required|integer|exists:guardians,id',
            'relationship' => 'required|in:mother,father,legal_guardian,grandparent,sibling,other',
            'is_primary_contact' => 'boolean',
            'is_emergency_contact' => 'boolean',
            'is_authorized_pickup' => 'boolean',
            'can_view_academic' => 'boolean',
            'can_view_finance' => 'boolean',
        ]);

        if (! empty($data['is_primary_contact'])) {
            StudentGuardian::where('student_id', $student->id)
                ->where('is_primary_contact', true)->whereNull('revoked_at')
                ->update(['is_primary_contact' => false]);
        }

        $link = StudentGuardian::updateOrCreate(
            ['student_id' => $student->id, 'guardian_id' => $data['guardian_id']],
            $data + ['student_id' => $student->id, 'revoked_at' => null, 'revoked_by' => null, 'revoke_reason' => null]
        );

        activity('guardians')->performedOn($student)->withProperties($data)->log('guardian.linked');

        return $link;
    }

    /** Portal access requires verification; it can be revoked with a reason. */
    public function verify(Request $request, StudentGuardian $link)
    {
        if ($link->revoked_at) {
            throw ValidationException::withMessages(['link' => 'A revoked link cannot be verified. Re-link the guardian instead.']);
        }

        $link->update([
            'verified_at' => now(),
            'verified_by' => $request->user()->id,
        ]);

        activity('guardians')->performedOn($link)->log('guardian.verified');

        return $link;
    }

    public function revoke(Request $request, StudentGuardian $link)
    {
        $data = $request->validate(['reason' => 'required|string|max:255']);

        $link->update([
            'revoked_at' => now(),
            'revoked_by' => $request->user()->id,
            'revoke_reason' => $data['reason'],
            'is_primary_contact' => false,
        ]);

        activity('guardians')->performedOn($link)->withProperties($data)->log('guardian.access_revoked');

        return $link;
    }
}
