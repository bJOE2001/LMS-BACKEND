<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

class VerifyERMSApiKey
{
    public function handle(Request $request, Closure $next): Response
    {
        $configuredApiKey = (string) env('ERMS_API_KEY', '');
        $incomingApiKey = (string) $request->header('X-ERMS-KEY', '');

        if ($configuredApiKey === '' || $incomingApiKey === '' || !hash_equals($configuredApiKey, $incomingApiKey)) {
            Log::warning('ERMS API authentication failed: invalid key', [
                'ip' => $request->ip(),
                'path' => $request->path(),
                'method' => $request->method(),
            ]);

            return response()->json([
                'status' => 'error',
                'message' => 'Unauthorized access',
            ], 403);
        }

        $allowedIpCsv = trim((string) env('ERMS_ALLOWED_IPS', ''));
        $singleAllowedIp = trim((string) env('ERMS_ALLOWED_IP', ''));

        $allowedIps = array_values(array_filter(array_map(
            static fn (string $ip): string => trim($ip),
            explode(',', $allowedIpCsv !== '' ? $allowedIpCsv : $singleAllowedIp)
        )));

        if ($allowedIps !== [] && !in_array((string) $request->ip(), $allowedIps, true)) {
            Log::warning('ERMS API authentication failed: IP not allowed', [
                'ip' => $request->ip(),
                'allowed_ips' => $allowedIps,
                'path' => $request->path(),
                'method' => $request->method(),
            ]);

            return response()->json([
                'status' => 'error',
                'message' => 'IP not allowed',
            ], 403);
        }

        return $next($request);
    }
}
