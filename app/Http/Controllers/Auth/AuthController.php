<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\LoginRequest;
use App\Models\DepartmentAdmin;
use App\Models\EmployeeAccount;
use App\Models\HRAccount;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class AuthController extends Controller
{
    private const DASHBOARD_HR = '/hr/dashboard';
    private const DASHBOARD_DEPARTMENT_ADMIN = '/admin/dashboard';
    private const DASHBOARD_EMPLOYEE = '/employee/dashboard';
    private const CHANGE_PASSWORD_EMPLOYEE = '/employee/change-password';

    /**
     * Handle a login request — authenticate via hr_accounts or department_admins, issue Sanctum token.
     */
    public function login(LoginRequest $request): JsonResponse
    {
        $request->authenticate();

        $account = $request->user();

        $token = DB::transaction(function () use ($account) {
            $account->tokens()->delete();
            return $account->createToken('auth-token')->plainTextToken;
        });

        [$userPayload, $dashboardRoute] = $this->formatAccount($account);

        $response = [
            'message' => 'Login successful.',
            'user' => $userPayload,
            'dashboard_route' => $dashboardRoute,
            'token' => $token,
        ];

        if ($account instanceof EmployeeAccount && $account->must_change_password) {
            $response['must_change_password'] = true;
            $response['redirect_to'] = self::CHANGE_PASSWORD_EMPLOYEE;
        }

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
     * @param HRAccount|DepartmentAdmin|EmployeeAccount $account
     * @return array{0: array, 1: string}
     */
    private function formatAccount(HRAccount|DepartmentAdmin|EmployeeAccount $account): array
    {
        if ($account instanceof HRAccount) {
            return [
                [
                    'id' => $account->id,
                    'name' => $account->full_name,
                    'username' => $account->username,
                    'role' => 'hr',
                ],
                self::DASHBOARD_HR,
            ];
        }

        if ($account instanceof EmployeeAccount) {
            $account->loadMissing(['employee', 'employee.department']);
            $emp = $account->employee;
            $name = $emp
                ? trim($emp->first_name . ' ' . $emp->last_name)
                : $account->username;
            $department = $emp && $emp->department ? ['id' => $emp->department->id, 'name' => $emp->department->name] : null;

            return [
                [
                    'id' => $account->id,
                    'employee_id' => $account->employee_id,
                    'name' => $name,
                    'first_name' => $emp?->first_name,
                    'last_name' => $emp?->last_name,
                    'username' => $account->username,
                    'role' => 'employee',
                    'must_change_password' => $account->must_change_password,
                    'department_id' => $emp?->department_id,
                    'department' => $department,
                    'department_name' => $department['name'] ?? null,
                    'position' => $emp?->position,
                ],
                $account->must_change_password ? self::CHANGE_PASSWORD_EMPLOYEE : self::DASHBOARD_EMPLOYEE,
            ];
        }

        $account->loadMissing('department');
        return [
            [
                'id' => $account->id,
                'name' => $account->full_name,
                'username' => $account->username,
                'role' => 'department_admin',
                'department_id' => $account->department_id,
                'department' => $account->department ? ['id' => $account->department->id, 'name' => $account->department->name] : null,
                'position' => 'Admin',
            ],
            self::DASHBOARD_DEPARTMENT_ADMIN,
        ];
    }
}
