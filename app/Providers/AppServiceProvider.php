<?php

namespace App\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\Rules\Password;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        RateLimiter::for('api', function (Request $request): array {
            $limit = max(1, (int) env('API_RATE_LIMIT_PER_MINUTE', 120));
            $user = $request->user();
            $key = $user
                ? sprintf('user:%s:%s', class_basename($user), (string) ($user->id ?? 'unknown'))
                : 'ip:' . $request->ip();

            return [
                Limit::perMinute($limit)->by($key),
            ];
        });

        Password::defaults(function () {
            return Password::min(8);
        });
    }
}
