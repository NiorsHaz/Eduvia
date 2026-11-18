<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Staff;
use App\Models\TemporaryRoleGrant;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\Models\Role;

class UserController extends Controller
{
    public function index(Request $request)
    {
        return User::with('roles')
            ->when($request->filled('q'), function ($q) use ($request) {
                $term = '%'.$request->string('q').'%';
                $q->where(fn ($w) => $w->where('name', 'like', $term)->orWhere('email', 'like', $term)->orWhere('phone', 'like', $term));
            })
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->input('status')))
            ->orderBy('name')->paginate(30);
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'name' => 'required|string|max:160',
            'email' => 'nullable|email|unique:users,email',
            'phone' => 'nullable|string|max:40|unique:users,phone',
            'password' => 'required|string|min:8',
            'locale' => 'in:fr,mg,en',
            'status' => 'in:active,suspended,deactivated',
            'roles' => 'array',
            'roles.*' => 'string|exists:roles,name',
            'staff_id' => 'nullable|integer|exists:staff,id',
        ]);

        if (empty($data['email']) && empty($data['phone'])) {
            throw ValidationException::withMessages(['email' => 'An email or a phone number is required.']);
        }

        $roles = $data['roles'] ?? [];
        $this->guardRoleAssignment($request, $roles);

        $staff = isset($data['staff_id']) ? Staff::find($data['staff_id']) : null;

        if ($staff?->user_id) {
            throw ValidationException::withMessages(['staff_id' => 'This staff member already has a login account.']);
        }

        $user = DB::transaction(function () use ($data, $roles, $staff) {
            $user = User::create([
                'name' => $data['name'],
                'email' => $data['email'] ?? null,
                'phone' => $data['phone'] ?? null,
                'password' => $data['password'],
                'locale' => $data['locale'] ?? 'fr',
                'status' => $data['status'] ?? 'active',
                'must_change_password' => true,
            ]);

            $user->syncRoles($roles);
            $staff?->update(['user_id' => $user->id]);

            return $user;
        });

        activity('users')->performedOn($user)->causedBy($request->user())
            ->withProperties(['roles' => $roles, 'staff_id' => $staff?->id])
            ->log('user.created');

        return response()->json($user->load('roles', 'staff'), 201);
    }

    public function update(Request $request, User $user)
    {
        $data = $request->validate([
            'name' => 'string|max:160',
            'email' => ['nullable', 'email', Rule::unique('users', 'email')->ignore($user->id)],
            'phone' => ['nullable', 'string', 'max:40', Rule::unique('users', 'phone')->ignore($user->id)],
            'password' => 'nullable|string|min:8',
            'locale' => 'in:fr,mg,en',
            'status' => 'in:active,suspended,deactivated',
            'roles' => 'array',
            'roles.*' => 'string|exists:roles,name',
        ]);

        if (array_key_exists('password', $data) && ! $data['password']) {
            unset($data['password']);
        }

        $this->guardAccountChange($request, $user);
        $this->guardRoleAssignment($request, $data['roles'] ?? []);
        $this->guardPasswordReset($request, $user, $data);
        $this->guardOwnDeactivation($request, $user, $data);

        $isSelf = (int) $user->id === (int) $request->user()->id;
        $passwordChanged = array_key_exists('password', $data);
        $rolesProvided = array_key_exists('roles', $data);
        $roles = $rolesProvided ? ($data['roles'] ?? []) : null;

        // Role names live in the pivot table; they must never be written to users.
        unset($data['roles']);

        $before = [
            'name' => $user->name,
            'email' => $user->email,
            'phone' => $user->phone,
            'locale' => $user->locale,
            'status' => $user->status,
            'roles' => $user->getRoleNames()->sort()->values()->all(),
        ];

        if ($passwordChanged) {
            // A password set by an administrator is temporary; changing your own is not.
            $data['must_change_password'] = ! $isSelf;
        }

        $user->update($data);

        if ($rolesProvided) {
            $user->syncRoles($roles);
        }

        $revoked = false;

        if ($passwordChanged && ! $isSelf) {
            // The previous holder of the account loses access on reset.
            $user->tokens()->delete();
            $revoked = true;
        } elseif ($passwordChanged) {
            $user->tokens()->whereKeyNot($request->user()->currentAccessToken()?->id)->delete();
        }

        if (isset($data['status']) && $data['status'] !== 'active') {
            $user->tokens()->delete();
            $revoked = true;
        }

        $changes = $this->changesFor($user->fresh(), $before, $passwordChanged);

        if ($changes) {
            activity('users')->performedOn($user)->causedBy($request->user())
                ->withProperties(['changes' => $changes, 'tokens_revoked' => $revoked])
                ->log('user.updated');
        }

        return $user->fresh('roles');
    }

    public function destroy(User $user)
    {
        if ($user->id === request()->user()->id) {
            throw ValidationException::withMessages(['user' => 'You cannot deactivate your own account.']);
        }

        $this->guardAccountChange(request(), $user);

        $previousStatus = $user->status;

        $user->update(['status' => 'deactivated']);
        $user->tokens()->delete();

        activity('users')->performedOn($user)->causedBy(request()->user())
            ->withProperties(['status' => ['from' => $previousStatus, 'to' => 'deactivated'], 'tokens_revoked' => true])
            ->log('user.deactivated');

        return response()->noContent();
    }

    public function roles()
    {
        return Role::with('permissions')->orderBy('name')->get();
    }

    /**
     * Account changes are audited field by field. Passwords are recorded as a
     * reset marker only — the value is never written to the activity log.
     *
     * @param  array<string, mixed>  $before
     * @return array<string, mixed>
     */
    private function changesFor(User $user, array $before, bool $passwordChanged): array
    {
        $changes = [];

        foreach (['name', 'email', 'phone', 'locale', 'status'] as $field) {
            if ((string) ($user->{$field} ?? '') !== (string) ($before[$field] ?? '')) {
                $changes[$field] = ['from' => $before[$field], 'to' => $user->{$field}];
            }
        }

        $roles = $user->getRoleNames()->sort()->values()->all();

        if ($roles !== $before['roles']) {
            $changes['roles'] = ['from' => $before['roles'], 'to' => $roles];
        }

        if ($passwordChanged) {
            $changes['password'] = 'reset';
        }

        return $changes;
    }

    /**
     * Only a super administrator may hand out the super administrator role.
     */
    private function guardRoleAssignment(Request $request, array $roles): void
    {
        if (in_array('super_admin', $roles, true) && ! $request->user()->hasRole('super_admin')) {
            throw ValidationException::withMessages([
                'roles' => 'Only a super administrator can grant the super administrator role.',
            ]);
        }
    }

    private function guardAccountChange(Request $request, User $user): void
    {
        abort_if($user->hasRole('super_admin') && ! $request->user()->hasRole('super_admin'), 403,
            'Only a super administrator can modify a super administrator account.');
    }

    /**
     * Resetting someone else's password is an impersonation risk, so it is
     * reserved for super administrators. Everyone may change their own.
     */
    private function guardPasswordReset(Request $request, User $user, array $data): void
    {
        if (empty($data['password'])) {
            return;
        }

        if ((int) $user->id === (int) $request->user()->id) {
            return;
        }

        abort_unless($request->user()->hasRole('super_admin'), 403,
            'Only a super administrator can reset another user\'s password.');
    }

    private function guardOwnDeactivation(Request $request, User $user, array $data): void
    {
        if (($data['status'] ?? 'active') === 'active') {
            return;
        }

        if ((int) $user->id === (int) $request->user()->id) {
            throw ValidationException::withMessages([
                'status' => 'You cannot deactivate or suspend your own account.',
            ]);
        }
    }

    public function grantTemporaryRole(Request $request, User $user)
    {
        $data = $request->validate([
            'role_name' => 'required|string|exists:roles,name',
            'starts_at' => 'required|date',
            'expires_at' => 'required|date|after:starts_at',
            'reason' => 'nullable|string|max:255',
        ]);

        $grant = TemporaryRoleGrant::create($data + [
            'user_id' => $user->id,
            'granted_by' => $request->user()->id,
        ]);

        activity('users')->performedOn($user)->causedBy($request->user())
            ->withProperties(['role_name' => $grant->role_name, 'starts_at' => $grant->starts_at, 'expires_at' => $grant->expires_at])
            ->log('user.temporary_role_granted');

        return response()->json($grant, 201);
    }

    public function loginHistory(User $user)
    {
        return $user->loginHistory()->orderByDesc('created_at')->limit(50)->get();
    }
}
