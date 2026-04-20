<?php

use Illuminate\Auth\AuthenticationException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->throttleApi();

        $middleware->alias([
            'hr' => \App\Http\Middleware\EnsureHR::class,
            'department_admin' => \App\Http\Middleware\EnsureDepartmentAdmin::class,
            'password.changed' => \App\Http\Middleware\EnsurePasswordChanged::class,
            'erms.auth' => \App\Http\Middleware\VerifyERMSApiKey::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->render(function (AuthenticationException $exception, Request $request) {
            if (!$request->is('api/*')) {
                return null;
            }

            $reason = trim((string) $request->attributes->get('sanctum_auth_failure_reason', ''));
            $message = match ($reason) {
                'concurrent_login' => 'This account was logged in on another device.',
                'idle_timeout' => 'Your session expired after 1 hour of inactivity.',
                'session_expired' => 'Your session has expired. Please log in again.',
                default => 'Unauthenticated.',
            };

            $shouldLogout = in_array($reason, [
                'concurrent_login',
                'idle_timeout',
                'session_expired',
            ], true);

            $payload = [
                'message' => $message,
                'should_logout' => $shouldLogout,
            ];
            if ($reason !== '') {
                $payload['reason'] = $reason;
            }

            return response()->json($payload, 401);
        });
    })->create();
