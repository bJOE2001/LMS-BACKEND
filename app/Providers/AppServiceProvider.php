<?php

namespace App\Providers;

use App\Models\DepartmentAdmin;
use App\Models\HRAccount;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\Rules\Password;
use Laravel\Sanctum\PersonalAccessToken;
use Laravel\Sanctum\Sanctum;

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
        Sanctum::authenticateAccessTokensUsing(function ($accessToken, bool $isValid): bool {
            return $this->authenticateSanctumAccessToken(
                $accessToken,
                $isValid,
                (int) config('sanctum.idle_timeout', 60)
            );
        });

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

    private function authenticateSanctumAccessToken(mixed $accessToken, bool $isValid, int $idleTimeoutMinutes): bool
    {
        if (!$accessToken instanceof PersonalAccessToken) {
            return $isValid;
        }

        if (!$isValid) {
            if ($this->supportsManagedSanctumSession($accessToken)) {
                $this->markSanctumAuthFailure('session_expired');
                $this->revokeInvalidatedSanctumToken($accessToken);
            }

            return false;
        }

        if (!$this->supportsManagedSanctumSession($accessToken)) {
            return $isValid;
        }

        if (
            (bool) config('sanctum.single_device_login', true)
            && !$this->matchesActiveAccountToken($accessToken)
        ) {
            $this->markSanctumAuthFailure('concurrent_login');
            $this->revokeInvalidatedSanctumToken($accessToken);

            return false;
        }

        if ($idleTimeoutMinutes <= 0) {
            return $isValid;
        }

        $lastActivityAt = $accessToken->last_used_at ?? $accessToken->created_at;
        if ($lastActivityAt === null) {
            return $isValid;
        }

        if ($lastActivityAt->gt(now()->subMinutes($idleTimeoutMinutes))) {
            return true;
        }

        $this->markSanctumAuthFailure('idle_timeout');
        $this->revokeInvalidatedSanctumToken($accessToken);

        return false;
    }

    private function supportsManagedSanctumSession(PersonalAccessToken $accessToken): bool
    {
        $tokenable = $accessToken->tokenable;

        return $tokenable instanceof HRAccount || $tokenable instanceof DepartmentAdmin;
    }

    private function matchesActiveAccountToken(PersonalAccessToken $accessToken): bool
    {
        $tokenable = $accessToken->tokenable;
        $activeTokenId = (int) ($tokenable->active_personal_access_token_id ?? 0);

        if ($activeTokenId <= 0) {
            return true;
        }

        return $activeTokenId === (int) $accessToken->getKey();
    }

    private function markSanctumAuthFailure(string $reason): void
    {
        $request = request();
        if ($request instanceof Request) {
            $request->attributes->set('sanctum_auth_failure_reason', $reason);
        }
    }

    private function revokeInvalidatedSanctumToken(PersonalAccessToken $accessToken): void
    {
        $tokenable = $accessToken->tokenable;
        if (
            ($tokenable instanceof HRAccount || $tokenable instanceof DepartmentAdmin)
            && (int) ($tokenable->active_personal_access_token_id ?? 0) === (int) $accessToken->getKey()
        ) {
            $tokenable->forceFill([
                'active_personal_access_token_id' => null,
            ])->save();
        }

        if ($accessToken->exists) {
            $accessToken->delete();
        }
    }
}
