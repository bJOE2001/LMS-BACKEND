<?php

namespace App\Http\Controllers;

use App\Models\DepartmentAdmin;
use App\Models\HRAccount;
use App\Models\HrisEmployee;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;

class SettingsController extends Controller
{
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
}
