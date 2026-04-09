<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\LoginRequest;
use App\Models\DepartmentAdmin;
use App\Models\HRAccount;
use App\Models\HrisEmployee;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\NewAccessToken;

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
        $token = $this->issueSingleDeviceToken($account);

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
        $account = $request->user();
        $currentToken = $account?->currentAccessToken();

        if (($account instanceof HRAccount || $account instanceof DepartmentAdmin) && $currentToken !== null) {
            DB::transaction(function () use ($account, $currentToken): void {
                if ((int) ($account->active_personal_access_token_id ?? 0) === (int) $currentToken->getKey()) {
                    $account->forceFill([
                        'active_personal_access_token_id' => null,
                    ])->save();
                }

                $currentToken->delete();
            });
        } elseif ($currentToken !== null) {
            $currentToken->delete();
        }

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

        $account->loadMissing(['department']);
        return [
            [
                'id' => $account->id,
                'name' => $this->resolveDepartmentAdminDisplayName($account),
                'username' => $account->username,
                'role' => 'department_admin',
                'employee_control_no' => trim((string) ($account->employee_control_no ?? '')) ?: null,
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
        $employee = $this->resolveDepartmentAdminEmployee($account);
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
        $employee = $this->resolveDepartmentAdminEmployee($account);
        $designation = trim((string) ($employee?->designation ?? ''));
        return $designation !== '' ? $designation : 'Department Admin';
    }

    private function resolveDepartmentAdminEmployee(DepartmentAdmin $account): ?object
    {
        $controlNo = trim((string) ($account->employee_control_no ?? ''));
        if ($controlNo === '') {
            return null;
        }

        return HrisEmployee::findByControlNo($controlNo);
    }

    private function issueSingleDeviceToken(HRAccount|DepartmentAdmin $account): string
    {
        $issuedToken = DB::transaction(function () use ($account): NewAccessToken {
            $previousActiveTokenId = (int) ($account->active_personal_access_token_id ?? 0);

            $staleTokenQuery = $account->tokens();
            if ($previousActiveTokenId > 0) {
                $staleTokenQuery->where('id', '!=', $previousActiveTokenId);
            }
            $staleTokenQuery->delete();

            $newAccessToken = $account->createToken('auth-token', ['*']);
            $account->forceFill([
                'active_personal_access_token_id' => (int) $newAccessToken->accessToken->getKey(),
            ])->save();

            return $newAccessToken;
        });

        return $issuedToken->plainTextToken;
    }
}
