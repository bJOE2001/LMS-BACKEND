<?php

namespace App\Http\Middleware;

use App\Models\DepartmentAdmin;
use Closure;
use Illuminate\Http\Request;
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

        return $next($request);
    }
}
