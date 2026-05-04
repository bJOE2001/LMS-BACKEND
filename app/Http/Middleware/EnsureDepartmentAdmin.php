<?php

namespace App\Http\Middleware;

use App\Models\DepartmentAdmin;
use Closure;
use Illuminate\Http\Request;
use Laravel\Sanctum\PersonalAccessToken;
use Symfony\Component\HttpFoundation\Response;

/**
 * Restrict access to department admin accounts only.
 */
class EnsureDepartmentAdmin
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user instanceof DepartmentAdmin) {
            return response()->json([
                'message' => 'Only department admin accounts can access this resource.',
            ], 403);
        }

        if ($user->isDeactivatedAccount()) {
            $currentToken = $user->currentAccessToken();
            if ($currentToken instanceof PersonalAccessToken) {
                $currentToken->delete();
            }

            if ((int) ($user->active_personal_access_token_id ?? 0) > 0) {
                $user->forceFill([
                    'active_personal_access_token_id' => null,
                ])->save();
            }

            return response()->json([
                'message' => 'This office admin account is deactivated. Please contact HR.',
            ], 403);
        }

        return $next($request);
    }
}
