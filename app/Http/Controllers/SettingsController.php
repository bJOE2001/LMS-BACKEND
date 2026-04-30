<?php

namespace App\Http\Controllers;

use App\Models\DepartmentAdmin;
use App\Models\HRAccount;
use App\Models\HrisEmployee;
use App\Models\SignatorySetting;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;

class SettingsController extends Controller
{
    private const CHRMO_LEAVE_IN_CHARGE_FALLBACK_POSITION = 'CHRMO Leave In-charge';

    /**
     * Get the current user's setting profile.
     */
    public function getProfile(Request $request): JsonResponse
    {
        $user = $request->user();

        if ($user instanceof HRAccount) {
            return response()->json([
                'username' => $user->username,
                'role' => $this->getRole($user),
                'full_name' => $user->full_name,
                'must_change_password' => (bool) $user->must_change_password,
                'full_name_editable' => true,
                'control_no' => null,
                'department' => null,
                'position' => $this->trimNullableString($user->position) ?? 'HR',
            ]);
        }

        if ($user instanceof DepartmentAdmin) {
            $user->loadMissing(['department']);

            return response()->json([
                'username' => $user->username,
                'role' => $this->getRole($user),
                'full_name' => $this->resolveDepartmentAdminFullName($user),
                'must_change_password' => (bool) $user->must_change_password,
                'full_name_editable' => false,
                'control_no' => $this->trimNullableString($user->employee_control_no),
                'department' => $user->department ? [
                    'id' => $user->department->id,
                    'name' => $user->department->name,
                ] : null,
                'position' => $this->resolveDepartmentAdminPosition($user),
            ]);
        }

        return response()->json([
            'username' => $user->username,
            'role' => $this->getRole($user),
        ]);
    }

    /**
     * Update profile information.
     */
    public function updateProfile(Request $request): JsonResponse
    {
        $user = $request->user();

        $rules = [
            'username' => ['required', 'string', 'max:255'],
        ];

        if ($user instanceof HRAccount) {
            $rules['full_name'] = ['required', 'string', 'max:255'];
            $rules['position'] = ['required', 'string', 'max:255'];
        }

        $validated = $request->validate($rules);
        $this->assertUsernameAvailable($user, (string) $validated['username']);

        $data = [
            'username' => trim((string) $validated['username']),
        ];

        if ($user instanceof HRAccount) {
            $data['full_name'] = trim((string) $validated['full_name']);
            $data['position'] = trim((string) $validated['position']);
        }

        $user->update($data);

        return response()->json([
            'message' => 'Profile updated successfully.',
            'user' => $user
        ]);
    }

    /**
     * Update the user's password.
     */
    public function updatePassword(Request $request): JsonResponse
    {
        $user = $request->user();

        $validated = $request->validate([
            'current_password' => ['required', 'string'],
            'password' => ['required', 'confirmed', Password::defaults()],
        ]);

        if (!Hash::check($validated['current_password'], $user->password)) {
            return response()->json([
                'message' => 'The provided password does not match your current password.',
                'errors' => [
                    'current_password' => ['The provided password does not match your current password.']
                ]
            ], 422);
        }

        $data = [
            'password' => Hash::make($validated['password']),
        ];

        if ($user instanceof HRAccount || $user instanceof DepartmentAdmin) {
            $data['must_change_password'] = false;
        }

        $user->update($data);

        return response()->json([
            'message' => 'Password updated successfully.'
        ]);
    }

    /**
     * Return signatory assignments used by leave-form printing.
     */
    public function getSignatories(Request $request): JsonResponse
    {
        $user = $request->user();
        if (!$user instanceof HRAccount && !$user instanceof DepartmentAdmin) {
            return response()->json([
                'message' => 'Only HR and department admin accounts can access this endpoint.',
            ], 403);
        }

        return response()->json([
            'signatories' => [
                SignatorySetting::KEY_CHRMO_LEAVE_IN_CHARGE => $this->resolveChrmoLeaveInChargeSignatory(),
            ],
        ]);
    }

    /**
     * Assign the CHRMO Leave In-charge signatory.
     */
    public function updateChrmoLeaveInCharge(Request $request): JsonResponse
    {
        $user = $request->user();
        if (!$user instanceof HRAccount) {
            return response()->json([
                'message' => 'Only HR accounts can update signatory assignments.',
            ], 403);
        }

        $validated = $request->validate([
            'employee_control_no' => ['required', 'string', 'max:64', 'regex:/^\d+$/'],
        ]);

        $employeeControlNo = trim((string) ($validated['employee_control_no'] ?? ''));
        $employee = HrisEmployee::findByControlNo($employeeControlNo, true);
        if (!$employee) {
            return response()->json([
                'message' => 'Selected employee was not found in active HRIS records.',
            ], 422);
        }

        $resolvedControlNo = trim((string) ($employee->control_no ?? ''));
        $resolvedFullName = $this->buildEmployeeFullName($employee);
        $resolvedPosition = $this->trimNullableString($employee->designation) ?? self::CHRMO_LEAVE_IN_CHARGE_FALLBACK_POSITION;

        $setting = SignatorySetting::query()->firstOrNew([
            'signatory_key' => SignatorySetting::KEY_CHRMO_LEAVE_IN_CHARGE,
        ]);

        $setting->employee_control_no = $resolvedControlNo !== '' ? $resolvedControlNo : $employeeControlNo;
        $setting->signatory_name = $resolvedFullName !== '' ? $resolvedFullName : null;
        $setting->signatory_position = $resolvedPosition;
        $setting->updated_by_hr_account_id = $user->id;
        $setting->save();

        return response()->json([
            'message' => 'CHRMO Leave In-charge signatory updated successfully.',
            'signatory' => $this->serializeSignatorySetting($setting),
        ]);
    }

    /**
     * Helper to get the role name.
     */
    private function getRole($user): string
    {
        if ($user instanceof HRAccount) return 'hr';
        if ($user instanceof DepartmentAdmin) return 'admin';
        return 'unknown';
    }

    private function resolveDepartmentAdminFullName(DepartmentAdmin $admin): string
    {
        $employee = $this->resolveDepartmentAdminEmployee($admin);
        if ($employee) {
            $parts = array_values(array_filter([
                $this->trimNullableString($employee->firstname),
                $this->trimNullableString($employee->middlename),
                $this->trimNullableString($employee->surname),
            ]));

            if ($parts !== []) {
                return implode(' ', $parts);
            }
        }

        return trim((string) $admin->full_name);
    }

    private function resolveDepartmentAdminPosition(DepartmentAdmin $admin): string
    {
        $employee = $this->resolveDepartmentAdminEmployee($admin);
        $position = $employee?->designation;
        $trimmedPosition = $this->trimNullableString($position);

        return $trimmedPosition ?? 'Department Admin';
    }

    private function resolveDepartmentAdminEmployee(DepartmentAdmin $admin): ?object
    {
        $controlNo = trim((string) ($admin->employee_control_no ?? ''));
        if ($controlNo === '') {
            return null;
        }

        return HrisEmployee::findByControlNo($controlNo);
    }

    private function assertUsernameAvailable(object $user, string $username): void
    {
        $normalizedUsername = trim($username);
        if ($normalizedUsername === '') {
            return;
        }

        $usedByHr = HRAccount::query()
            ->where('username', $normalizedUsername)
            ->when($user instanceof HRAccount, fn ($query) => $query->where('id', '!=', $user->id))
            ->exists();

        if ($usedByHr) {
            throw ValidationException::withMessages([
                'username' => ['The username has already been taken.'],
            ]);
        }

        $usedByDepartmentAdmin = DepartmentAdmin::query()
            ->where('username', $normalizedUsername)
            ->when($user instanceof DepartmentAdmin, fn ($query) => $query->where('id', '!=', $user->id))
            ->exists();

        if ($usedByDepartmentAdmin) {
            throw ValidationException::withMessages([
                'username' => ['The username has already been taken.'],
            ]);
        }
    }

    private function trimNullableString(mixed $value): ?string
    {
        $trimmed = trim((string) ($value ?? ''));
        return $trimmed === '' ? null : $trimmed;
    }

    private function buildEmployeeFullName(object $employee): string
    {
        $parts = array_values(array_filter([
            $this->trimNullableString($employee->firstname ?? null),
            $this->trimNullableString($employee->middlename ?? null),
            $this->trimNullableString($employee->surname ?? null),
        ]));

        if ($parts === []) {
            return trim((string) ($employee->control_no ?? ''));
        }

        return trim(implode(' ', $parts));
    }

    private function resolveChrmoLeaveInChargeSignatory(): ?array
    {
        $setting = SignatorySetting::query()
            ->where('signatory_key', SignatorySetting::KEY_CHRMO_LEAVE_IN_CHARGE)
            ->first();

        if (!$setting) {
            return null;
        }

        return $this->serializeSignatorySetting($setting);
    }

    private function serializeSignatorySetting(SignatorySetting $setting): ?array
    {
        $controlNo = trim((string) ($setting->employee_control_no ?? ''));
        $employee = $controlNo !== '' ? HrisEmployee::findByControlNo($controlNo) : null;

        $fullName = $employee
            ? $this->buildEmployeeFullName($employee)
            : ($this->trimNullableString($setting->signatory_name) ?? '');
        $position = $this->trimNullableString($employee?->designation)
            ?? $this->trimNullableString($setting->signatory_position)
            ?? self::CHRMO_LEAVE_IN_CHARGE_FALLBACK_POSITION;

        if ($fullName === '') {
            return null;
        }

        return [
            'employee_control_no' => $controlNo !== '' ? $controlNo : null,
            'control_no' => $controlNo !== '' ? $controlNo : null,
            'full_name' => $fullName,
            'name' => $fullName,
            'designation' => $position,
            'position' => $position,
            'firstname' => $employee ? $this->trimNullableString($employee->firstname) : null,
            'middlename' => $employee ? $this->trimNullableString($employee->middlename) : null,
            'surname' => $employee ? $this->trimNullableString($employee->surname) : null,
            'office' => $employee ? $this->trimNullableString($employee->office) : null,
        ];
    }
}
