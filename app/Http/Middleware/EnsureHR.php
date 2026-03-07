<?php

namespace App\Http\Middleware;

use App\Models\HRAccount;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Restrict access to HR accounts only.
 */
class EnsureHR
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        if (!$user instanceof HRAccount) {
            return response()->json([
                'message' => 'Only HR accounts can access this resource.',
            ], 403);
        }
        return $next($request);
    }
}
