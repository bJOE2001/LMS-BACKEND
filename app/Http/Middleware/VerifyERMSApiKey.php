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
        $trustedOrigins = $this->trustedOrigins();
        $incomingApiKey = trim((string) $request->header($this->headerName(), ''));
        $requestOrigin = $this->requestOrigin($request);

        if ($incomingApiKey !== '' && $this->matchesAnyConfiguredKey($incomingApiKey, $configuredKeys)) {
            $allowedIps = $this->allowedIps();
            if ($allowedIps !== [] && !in_array((string) $request->ip(), $allowedIps, true)) {
                $this->logFailure('ip_not_allowed', $request, [
                    'allowed_ips' => $allowedIps,
                    'auth_mode' => 'api_key',
                ]);

                return $this->forbiddenResponse('IP not allowed', 'ERMS_IP_NOT_ALLOWED');
            }

            return $next($request);
        }

        if ($this->matchesAnyTrustedOrigin($requestOrigin, $trustedOrigins)) {
            return $next($request);
        }

        if ($incomingApiKey !== '') {
            $this->logFailure('invalid_key', $request, [
                'origin' => $requestOrigin,
            ]);

            return $this->forbiddenResponse('Unauthorized access', 'ERMS_UNAUTHORIZED');
        }

        if ($configuredKeys === [] && $trustedOrigins === []) {
            $this->logFailure('missing_configuration', $request);

            return $this->forbiddenResponse('Unauthorized access', 'ERMS_UNAUTHORIZED');
        }

        $this->logFailure($requestOrigin === null ? 'missing_credentials' : 'origin_not_allowed', $request, [
            'origin' => $requestOrigin,
        ]);

        return $this->forbiddenResponse('Unauthorized access', 'ERMS_UNAUTHORIZED');
    }

    private function configuredKeys(): array
    {
        return array_values(array_filter(array_map(
            static fn (mixed $key): string => trim((string) $key),
            (array) config('erms.keys', [])
        ), static fn (string $key): bool => $key !== ''));
    }

    private function trustedOrigins(): array
    {
        return array_values(array_filter(array_map(
            static fn (mixed $origin): string => trim((string) $origin),
            (array) config('erms.trusted_origins', [])
        ), static fn (string $origin): bool => $origin !== '' && $origin !== '*'));
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

    private function matchesAnyTrustedOrigin(?string $requestOrigin, array $trustedOrigins): bool
    {
        if ($requestOrigin === null) {
            return false;
        }

        foreach ($trustedOrigins as $trustedOrigin) {
            $normalizedTrustedOrigin = $this->normalizeOrigin((string) $trustedOrigin);

            if ($normalizedTrustedOrigin !== null && hash_equals($normalizedTrustedOrigin, $requestOrigin)) {
                return true;
            }
        }

        return false;
    }

    private function requestOrigin(Request $request): ?string
    {
        $originHeader = trim((string) $request->headers->get('Origin', ''));
        if ($originHeader !== '') {
            return $this->normalizeOrigin($originHeader);
        }

        $refererHeader = trim((string) $request->headers->get('Referer', ''));
        if ($refererHeader === '') {
            return null;
        }

        $refererParts = parse_url($refererHeader);
        if (!is_array($refererParts) || !isset($refererParts['scheme'], $refererParts['host'])) {
            return null;
        }

        $refererOrigin = sprintf(
            '%s://%s%s',
            $refererParts['scheme'],
            $refererParts['host'],
            isset($refererParts['port']) ? ':'.$refererParts['port'] : ''
        );

        return $this->normalizeOrigin($refererOrigin);
    }

    private function normalizeOrigin(string $origin): ?string
    {
        $origin = trim($origin);
        if ($origin === '' || $origin === 'null') {
            return null;
        }

        $parts = parse_url($origin);
        if (!is_array($parts) || !isset($parts['scheme'], $parts['host'])) {
            return null;
        }

        $scheme = strtolower(trim((string) $parts['scheme']));
        $host = strtolower(trim((string) $parts['host']));
        if ($scheme === '' || $host === '') {
            return null;
        }

        $port = isset($parts['port']) ? ':'.$parts['port'] : '';

        return "{$scheme}://{$host}{$port}";
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
