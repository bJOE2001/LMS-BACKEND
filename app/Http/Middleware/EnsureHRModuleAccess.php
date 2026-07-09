<?php

namespace App\Http\Middleware;

use App\Models\HRAccount;
use App\Services\HrAccessControlService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureHRModuleAccess
{
    /**
     * Handle an incoming request.
     *
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     */
    public function handle(Request $request, Closure $next, string ...$moduleKeys): Response
    {
        $account = $request->user();
        if (! $account instanceof HRAccount) {
            return response()->json([
                'message' => 'Only HR accounts can access this resource.',
            ], 403);
        }

        $accessControl = app(HrAccessControlService::class);

        $hasAccess = false;
        $hasValidKey = false;

        foreach ($moduleKeys as $moduleKey) {
            if ($accessControl->isValidModuleKey($moduleKey)) {
                $hasValidKey = true;
                if ($accessControl->hasModuleAccess($account, $moduleKey)) {
                    $hasAccess = true;
                    break;
                }
            }
        }

        if (! $hasValidKey) {
            return response()->json([
                'message' => 'Invalid HR module access key.',
            ], 403);
        }

        if (! $hasAccess) {
            return response()->json([
                'message' => 'You do not have access to this module.',
            ], 403);
        }

        return $next($request);
    }
}
