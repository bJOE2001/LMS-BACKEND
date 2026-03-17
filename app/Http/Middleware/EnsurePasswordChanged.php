<?php

namespace App\Http\Middleware;

use App\Models\DepartmentAdmin;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Require department admins with temporary credentials to update their password
 * before using protected resources outside account settings.
 */
class EnsurePasswordChanged
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user instanceof DepartmentAdmin || ! (bool) $user->must_change_password) {
            return $next($request);
        }

        if (
            $request->is('api/logout')
            || $request->is('api/me')
            || $request->is('api/settings/profile')
            || $request->is('api/settings/password')
        ) {
            return $next($request);
        }

        return response()->json([
            'message' => 'Please change your password first before accessing other features.',
            'must_change_password' => true,
        ], 403);
    }
}
