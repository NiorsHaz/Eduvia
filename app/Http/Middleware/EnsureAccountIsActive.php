<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * A token stays valid until it is revoked, so deactivating or suspending an
 * account must be enforced on every request, not only at login. Any token
 * presented for a non-active account is deleted immediately.
 */
class EnsureAccountIsActive
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user && $user->status !== 'active') {
            $user->tokens()->delete();

            return response()->json([
                'message' => 'This account is not active. Contact the administration.',
                'code' => 'account_inactive',
            ], 401);
        }

        return $next($request);
    }
}
