<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureUserIsActive
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user && $user->status === 'suspended') {
            // Revoke any tokens this user holds so a previously-issued token
            // is invalidated the moment the account is suspended.
            if (method_exists($user, 'tokens')) {
                $user->tokens()->delete();
            }

            return response()->json([
                'message' => 'Your account has been suspended.',
            ], Response::HTTP_FORBIDDEN);
        }

        return $next($request);
    }
}
