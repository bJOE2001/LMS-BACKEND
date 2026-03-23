<?php

namespace Database\Seeders;

use App\Models\Department;
use App\Models\DepartmentAdmin;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * Seed one immutable default Department Admin account per Department.
 *
 * Policy:
 * - keeps employee-assigned admin accounts untouched
 * - creates/repairs only the default seeded account (is_default_account=true)
 * - never exceeds 2 accounts per department
 */
class DepartmentAdminSeeder extends Seeder
{
    public function run(): void
    {
        $seedPassword = trim((string) env('DEPARTMENT_ADMIN_SEEDER_PASSWORD', ''));
        if ($seedPassword === '') {
            $seedPassword = trim((string) env('HR_SEEDER_PASSWORD', ''));
        }

        if ($seedPassword === '') {
            throw new \RuntimeException(
                'DEPARTMENT_ADMIN_SEEDER_PASSWORD (or HR_SEEDER_PASSWORD fallback) is required to run DepartmentAdminSeeder.'
            );
        }

        $resetExistingPasswords = filter_var(
            (string) env('DEPARTMENT_ADMIN_SEEDER_RESET_PASSWORDS', 'false'),
            FILTER_VALIDATE_BOOLEAN
        );

        DB::transaction(function () use ($seedPassword, $resetExistingPasswords): void {
            $departments = Department::query()
                ->orderBy('id')
                ->get(['id', 'name']);

            foreach ($departments as $department) {
                $defaultAccount = DepartmentAdmin::query()
                    ->where('department_id', $department->id)
                    ->where('is_default_account', true)
                    ->first();

                if (!$defaultAccount) {
                    // Reuse an empty non-default slot when available.
                    $defaultAccount = DepartmentAdmin::query()
                        ->where('department_id', $department->id)
                        ->where('is_default_account', false)
                        ->where(function ($query): void {
                            $query->whereNull('employee_control_no')
                                ->orWhereRaw("LTRIM(RTRIM(CONVERT(VARCHAR(64), employee_control_no))) = ''");
                        })
                        ->orderBy('id')
                        ->first();
                }

                if (!$defaultAccount) {
                    $accountCount = DepartmentAdmin::query()
                        ->where('department_id', $department->id)
                        ->count();

                    if ($accountCount >= 2) {
                        // Keep max account policy; skip when no slot is available.
                        continue;
                    }

                    $defaultAccount = new DepartmentAdmin([
                        'department_id' => $department->id,
                    ]);
                }

                $baseUsername = 'dept_admin_' . $department->id;
                $username = $this->resolveUniqueUsername(
                    $baseUsername,
                    $defaultAccount->exists ? (int) $defaultAccount->id : null
                );

                $payload = [
                    'department_id' => $department->id,
                    'is_default_account' => true,
                    'employee_control_no' => null,
                    'full_name' => $this->buildSeedFullName((string) $department->name),
                    'username' => $username,
                    'must_change_password' => true,
                ];

                if (!$defaultAccount->exists || $resetExistingPasswords) {
                    $payload['password'] = Hash::make($seedPassword);
                }

                $defaultAccount->forceFill($payload)->save();
            }
        });
    }

    private function buildSeedFullName(string $departmentName): string
    {
        $name = trim($departmentName);
        if ($name === '') {
            return 'Department Admin';
        }

        return Str::limit('Department Admin' , 255, '');
    }

    private function resolveUniqueUsername(string $baseUsername, ?int $ignoreDepartmentAdminId = null): string
    {
        $base = strtolower(trim($baseUsername));
        if ($base === '') {
            $base = 'department_admin';
        }

        $base = Str::limit($base, 240, '');
        $candidate = $base;
        $counter = 1;

        while (
            DepartmentAdmin::query()
                ->where('username', $candidate)
                ->when($ignoreDepartmentAdminId !== null, function ($query) use ($ignoreDepartmentAdminId): void {
                    $query->where('id', '!=', $ignoreDepartmentAdminId);
                })
                ->exists()
        ) {
            $suffix = '_' . $counter;
            $candidate = Str::limit($base, 240 - strlen($suffix), '') . $suffix;
            $counter++;
        }

        return $candidate;
    }
}
