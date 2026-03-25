<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

class VerifyERMSApiKey
{
    public function handle(Request $request, Closure $next): Response
    {
        $configuredKeys = $this->configuredKeys();
        $incomingApiKey = trim((string) $request->header($this->headerName(), ''));

        if ($configuredKeys === []) {
            $this->logFailure('missing_configuration', $request);

            return $this->forbiddenResponse('Unauthorized access', 'ERMS_UNAUTHORIZED');
        }

        if ($incomingApiKey === '' || !$this->matchesAnyConfiguredKey($incomingApiKey, $configuredKeys)) {
            $this->logFailure('invalid_key', $request);

            return $this->forbiddenResponse('Unauthorized access', 'ERMS_UNAUTHORIZED');
        }

        $allowedIps = $this->allowedIps();
        if ($allowedIps !== [] && !in_array((string) $request->ip(), $allowedIps, true)) {
            $this->logFailure('ip_not_allowed', $request, [
                'allowed_ips' => $allowedIps,
            ]);

            return $this->forbiddenResponse('IP not allowed', 'ERMS_IP_NOT_ALLOWED');
        }

        return $next($request);
    }

    private function configuredKeys(): array
    {
        return array_values(array_filter(array_map(
            static fn (mixed $key): string => trim((string) $key),
            (array) config('erms.keys', [])
        ), static fn (string $key): bool => $key !== ''));
    }

    private function allowedIps(): array
    {
        return array_values(array_filter(array_map(
            static fn (mixed $ip): string => trim((string) $ip),
            (array) config('erms.allowed_ips', [])
        ), static fn (string $ip): bool => $ip !== ''));
    }

    private function headerName(): string
    {
        $headerName = trim((string) config('erms.header', 'X-ERMS-KEY'));

        return $headerName !== '' ? $headerName : 'X-ERMS-KEY';
    }

    private function matchesAnyConfiguredKey(string $incomingApiKey, array $configuredKeys): bool
    {
        foreach ($configuredKeys as $configuredKey) {
            if (hash_equals($configuredKey, $incomingApiKey)) {
                return true;
            }
        }

        return false;
    }

    private function forbiddenResponse(string $message, string $code): JsonResponse
    {
        return response()->json([
            'status' => 'error',
            'message' => $message,
            'code' => $code,
        ], 403);
    }

    private function logFailure(string $reason, Request $request, array $context = []): void
    {
        Log::warning('ERMS API authentication failed', array_merge([
            'reason' => $reason,
            'ip' => $request->ip(),
            'path' => $request->path(),
            'method' => $request->method(),
        ], $context));
    }
}
