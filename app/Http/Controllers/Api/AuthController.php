<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\LoginHistory;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    public function login(Request $request)
    {
        $data = $request->validate([
            'identifier' => 'required|string',
            'password' => 'required|string',
            'device_name' => 'nullable|string|max:80',
        ]);

        $user = User::where('email', $data['identifier'])
            ->orWhere('phone', $data['identifier'])
            ->first();

        $valid = $user && Hash::check($data['password'], $user->password);

        LoginHistory::create([
            'user_id' => $user?->id,
            'identifier' => $data['identifier'],
            'success' => $valid,
            'ip_address' => $request->ip(),
            'user_agent' => substr((string) $request->userAgent(), 0, 500),
            'created_at' => now(),
        ]);

        if (! $valid) {
            throw ValidationException::withMessages(['identifier' => 'These credentials do not match our records.']);
        }

        if ($user->status !== 'active') {
            throw ValidationException::withMessages(['identifier' => 'This account is not active. Contact the administration.']);
        }

        $user->forceFill(['last_login_at' => now()])->save();
        $token = $user->createToken($data['device_name'] ?? 'web')->plainTextToken;

        return [
            'token' => $token,
            'user' => $this->payload($user->fresh()),
        ];
    }

    public function me(Request $request)
    {
        return $this->payload($request->user());
    }

    public function logout(Request $request)
    {
        $request->user()->currentAccessToken()?->delete();

        return response()->noContent();
    }

    public function changePassword(Request $request)
    {
        $data = $request->validate([
            'current_password' => 'required|string',
            'password' => 'required|string|min:8|confirmed|different:current_password',
        ]);

        if (! Hash::check($data['current_password'], $request->user()->password)) {
            throw ValidationException::withMessages(['current_password' => 'The current password is incorrect.']);
        }

        $request->user()->update([
            'password' => $data['password'],
            'must_change_password' => false,
        ]);
        $request->user()->tokens()->whereKeyNot($request->user()->currentAccessToken()?->id)->delete();

        activity('users')->performedOn($request->user())->causedBy($request->user())
            ->withProperties(['password' => 'changed', 'other_tokens_revoked' => true])
            ->log('user.password_changed');

        return response()->noContent();
    }

    private function payload(User $user): array
    {
        $staff = $user->staff;
        $guardian = $user->guardian;

        return [
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'phone' => $user->phone,
            'locale' => $user->locale,
            'status' => $user->status,
            'roles' => $user->getRoleNames(),
            'permissions' => $user->getAllPermissions()->pluck('name'),
            'must_change_password' => (bool) $user->must_change_password,
            'staff_id' => $staff?->id,
            'guardian_id' => $guardian?->id,
        ];
    }
}
