<?php

return [

    /*
    |--------------------------------------------------------------------------
    | ERMS Integration
    |--------------------------------------------------------------------------
    |
    | Keep ERMS_API_KEY for single-key setups. Use ERMS_API_KEYS when you
    | need key rotation so old and new keys can overlap without downtime.
    | Browser clients like HRPDS can be allowed by trusted origin instead.
    |
    */
    'header' => 'X-ERMS-KEY',

    'keys' => (static function (): array {
        $configuredKeys = trim((string) env('ERMS_API_KEYS', ''));

        if ($configuredKeys === '') {
            $configuredKeys = trim((string) env('ERMS_API_KEY', ''));
        }

        return array_values(array_unique(array_filter(
            array_map(
                static fn (string $key): string => trim($key),
                explode(',', $configuredKeys)
            ),
            static fn (string $key): bool => $key !== ''
        )));
    })(),

    'trusted_origins' => (static function (): array {
        $configuredOrigins = trim((string) env('ERMS_TRUSTED_ORIGINS', ''));

        if ($configuredOrigins === '') {
            $configuredOrigins = trim((string) env('CORS_ALLOWED_ORIGINS', ''));
        }

        if ($configuredOrigins === '') {
            $configuredOrigins = trim((string) env('FRONTEND_URL', ''));
        }

        return array_values(array_unique(array_filter(
            array_map(
                static fn (string $origin): string => trim($origin),
                explode(',', $configuredOrigins)
            ),
            static fn (string $origin): bool => $origin !== '' && $origin !== '*'
        )));
    })(),

    'allowed_ips' => (static function (): array {
        $configuredIps = trim((string) env('ERMS_ALLOWED_IPS', ''));

        if ($configuredIps === '') {
            $configuredIps = trim((string) env('ERMS_ALLOWED_IP', ''));
        }

        return array_values(array_unique(array_filter(
            array_map(
                static fn (string $ip): string => trim($ip),
                explode(',', $configuredIps)
            ),
            static fn (string $ip): bool => $ip !== ''
        )));
    })(),

];
