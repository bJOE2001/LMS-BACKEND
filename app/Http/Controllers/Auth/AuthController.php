<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\LoginRequest;
use App\Models\DepartmentAdmin;
use App\Models\HRAccount;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class AuthController extends Controller
{
    private const DASHBOARD_HR = '/hr/dashboard';
    private const DASHBOARD_DEPARTMENT_ADMIN = '/admin/dashboard';

    /**
     * Handle a login request — authenticate via hr_accounts or department_admins, issue Sanctum token.
     */
    public function login(LoginRequest $request): JsonResponse
    {
        $account = $request->authenticate();
        $tokenExpirationMinutes = (int) config('sanctum.expiration', 120);
        $tokenExpiresAt = $tokenExpirationMinutes > 0
            ? now()->addMinutes($tokenExpirationMinutes)
            : null;

        $token = DB::transaction(function () use ($account, $tokenExpiresAt) {
            return $account->createToken('auth-token', ['*'], $tokenExpiresAt)->plainTextToken;
        });

        [$userPayload, $dashboardRoute] = $this->formatAccount($account);

        $response = [
            'message' => 'Login successful.',
            'user' => $userPayload,
            'must_change_password' => (bool) ($userPayload['must_change_password'] ?? false),
            'dashboard_route' => $dashboardRoute,
            'token' => $token,
        ];

        return response()->json($response);
    }

    /**
     * Handle a logout request — revoke the current token.
     */
    public function logout(Request $request): JsonResponse
    {
        $request->user()->currentAccessToken()->delete();

        return response()->json([
            'message' => 'Logged out successfully.',
        ]);
    }

    /**
     * Return the currently authenticated account (HRAccount or DepartmentAdmin).
     */
    public function me(Request $request): JsonResponse
    {
        $account = $request->user();
        [$userPayload, $dashboardRoute] = $this->formatAccount($account);

        return response()->json([
            'user' => $userPayload,
            'dashboard_route' => $dashboardRoute,
        ]);
    }

    /**
     * @param HRAccount|DepartmentAdmin $account
     * @return array{0: array, 1: string}
     */
    private function formatAccount(HRAccount|DepartmentAdmin $account): array
    {
        if ($account instanceof HRAccount) {
            return [
                [
                    'id' => $account->id,
                    'name' => $account->full_name,
                    'username' => $account->username,
                    'role' => 'hr',
                    'position' => trim((string) ($account->position ?? '')) !== '' ? $account->position : 'HR',
                    'must_change_password' => (bool) $account->must_change_password,
                ],
                self::DASHBOARD_HR,
            ];
        }

        $account->loadMissing(['department', 'employee']);
        return [
            [
                'id' => $account->id,
                'name' => $this->resolveDepartmentAdminDisplayName($account),
                'username' => $account->username,
                'role' => 'department_admin',
                'department_id' => $account->department_id,
                'department' => $account->department ? ['id' => $account->department->id, 'name' => $account->department->name] : null,
                'position' => $this->resolveDepartmentAdminPosition($account),
                'must_change_password' => (bool) $account->must_change_password,
            ],
            self::DASHBOARD_DEPARTMENT_ADMIN,
        ];
    }

    private function resolveDepartmentAdminDisplayName(DepartmentAdmin $account): string
    {
        $employee = $account->employee;
        if ($employee) {
            $parts = array_values(array_filter([
                trim((string) $employee->firstname),
                trim((string) $employee->middlename),
                trim((string) $employee->surname),
            ], fn (string $part): bool => $part !== ''));

            if ($parts !== []) {
                return implode(' ', $parts);
            }
        }

        return trim((string) $account->full_name);
    }

    private function resolveDepartmentAdminPosition(DepartmentAdmin $account): string
    {
        $designation = trim((string) ($account->employee?->designation ?? ''));
        return $designation !== '' ? $designation : 'Department Admin';
    }
}
