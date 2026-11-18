<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Accounts created by an administrator start with a temporary password. Until
 * it is replaced, only the authentication endpoints (me, logout, change
 * password) are reachable.
 */
class EnsurePasswordIsCurrent
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user?->must_change_password && ! $request->is('api/v1/auth', 'api/v1/auth/*')) {
            return response()->json([
                'message' => 'You must change your password before continuing.',
                'code' => 'password_change_required',
            ], 423);
        }

        return $next($request);
    }
}
