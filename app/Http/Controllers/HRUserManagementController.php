<?php

namespace App\Http\Controllers;

use App\Models\Department;
use App\Models\DepartmentAdmin;
use App\Models\HRAccount;
use App\Models\HrisEmployee;
use App\Models\LeaveBalance;
use App\Models\LeaveApplication;
use App\Services\RecycleBinService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class HRUserManagementController extends Controller
{
    private const HR_DEFAULT_DEPARTMENT = 'OFFICE OF THE CITY HUMAN RESOURCE MANAGEMENT OFFICER';

    /**
     * List user accounts for HR and Department Admin roles.
     */
    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'search' => ['nullable', 'string', 'max:100'],
        ]);
        $currentUser = $request->user();
        $currentHrAccountId = $currentUser instanceof HRAccount ? (int) $currentUser->id : null;

        $searchTerm = trim((string) ($validated['search'] ?? ''));

        $departmentAdminAccounts = DepartmentAdmin::query()
            ->with(['department:id,name'])
            ->orderBy('full_name')
            ->get()
            ->map(fn(DepartmentAdmin $admin): array => $this->serializeDepartmentAdminAccountRow($admin))
            ->values();

        $hrAccounts = HRAccount::query()
            ->when($currentHrAccountId !== null, function ($query) use ($currentHrAccountId): void {
                $query->where('id', '!=', $currentHrAccountId);
            })
            ->orderBy('full_name')
            ->get()
            ->map(fn(HRAccount $account): array => $this->serializeHrAccountRow($account))
            ->values();

        $needle = mb_strtolower($searchTerm);

        $accounts = $departmentAdminAccounts
            ->concat($hrAccounts)
            ->filter(function (array $account) use ($needle): bool {
                if ($needle === '') {
                    return true;
                }

                $haystack = mb_strtolower(implode(' ', array_filter([
                    trim((string) ($account['full_name'] ?? '')),
                    trim((string) ($account['username'] ?? '')),
                    trim((string) ($account['role_label'] ?? '')),
                    trim((string) ($account['department'] ?? '')),
                    trim((string) ($account['position'] ?? '')),
                    trim((string) ($account['employee_control_no'] ?? '')),
                ], fn(string $value): bool => $value !== '')));

                return str_contains($haystack, $needle);
            })
            ->sort(function (array $left, array $right): int {
                $leftRole = trim((string) ($left['role_label'] ?? ''));
                $rightRole = trim((string) ($right['role_label'] ?? ''));
                if ($leftRole !== $rightRole) {
                    return $leftRole <=> $rightRole;
                }

                $leftName = mb_strtoupper(trim((string) ($left['full_name'] ?? '')));
                $rightName = mb_strtoupper(trim((string) ($right['full_name'] ?? '')));
                if ($leftName !== $rightName) {
                    return $leftName <=> $rightName;
                }

                return strcmp(
                    trim((string) ($left['username'] ?? '')),
                    trim((string) ($right['username'] ?? ''))
                );
            })
            ->values();

        return response()->json([
            'accounts' => $accounts,
            'summary' => [
                'total_accounts' => $accounts->count(),
                'hr_accounts' => $accounts->where('role', 'HR')->count(),
                'department_admin_accounts' => $accounts->where('role', 'DEPARTMENT_ADMIN')->count(),
            ],
        ]);
    }

    /**
     * List eligible employees:
     * - all ACTIVE employees (regardless of selected department)
     */
    public function eligibleEmployees(Request $request, ?int $departmentId = null): JsonResponse
    {
        $validated = $request->validate([
            'search' => ['nullable', 'string', 'max:100'],
            'limit' => ['nullable', 'integer', 'min:1', 'max:25'],
            'department_id' => [
                'nullable',
                'integer',
                Rule::exists('tblDepartments', 'id')->where(fn ($query) => $query->where('is_inactive', false)),
            ],
        ]);

        $resolvedDepartmentId = $departmentId ?? (isset($validated['department_id']) ? (int) $validated['department_id'] : null);
        $department = $resolvedDepartmentId !== null
            ? Department::query()->active()->find($resolvedDepartmentId)
            : null;
        if ($resolvedDepartmentId !== null && !$department) {
            return response()->json([
                'message' => 'Department not found.',
            ], 404);
        }

        $searchTerm = trim((string) ($validated['search'] ?? ''));
        $limit = max(1, min(25, (int) ($validated['limit'] ?? 20)));

        $employees = HrisEmployee::allCached(true)
            ->filter(function (object $employee) use ($searchTerm): bool {
                if ($searchTerm === '') {
                    return true;
                }

                $haystacks = [
                    trim((string) ($employee->control_no ?? '')),
                    trim((string) ($employee->surname ?? '')),
                    trim((string) ($employee->firstname ?? '')),
                    trim((string) ($employee->middlename ?? '')),
                    trim((string) ($employee->designation ?? '')),
                    trim((string) ($employee->office ?? '')),
                ];

                foreach ($haystacks as $haystack) {
                    if ($haystack !== '' && stripos($haystack, $searchTerm) !== false) {
                        return true;
                    }
                }

                return false;
            })
            ->take($limit)
            ->values()
            ->map(fn(object $employee): array => $this->serializeEligibleEmployee($employee))
            ->values();

        return response()->json([
            'department' => $department
                ? [
                    'id' => $department->id,
                    'name' => $department->name,
                ]
                : null,
            'employees' => $employees,
        ]);
    }

    /**
     * Create either an HR account or an office admin account.
     */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'is_hr_admin' => ['nullable', 'boolean'],
            'department_id' => [
                'nullable',
                'integer',
                Rule::exists('tblDepartments', 'id')->where(fn ($query) => $query->where('is_inactive', false)),
            ],
            'is_guest' => ['nullable', 'boolean'],
            'employee_control_no' => ['nullable', 'string', 'max:50', 'regex:/^\d+$/'],
            'password' => ['nullable', 'string', 'min:8', 'max:255'],
            'username' => ['required', 'string', 'max:255'],
        ]);

        $isHrAdmin = filter_var((string) ($validated['is_hr_admin'] ?? false), FILTER_VALIDATE_BOOLEAN);
        $departmentId = isset($validated['department_id']) ? (int) $validated['department_id'] : null;

        if ($isHrAdmin) {
            return $this->storeHrAccount($validated);
        }

        if (!$departmentId) {
            throw ValidationException::withMessages([
                'department_id' => ['Office is required when creating an office admin account.'],
            ]);
        }

        $department = Department::query()->active()->find($departmentId);
        if (!$department) {
            return response()->json([
                'message' => 'Department not found.',
            ], 404);
        }

        $isGuest = filter_var((string) ($validated['is_guest'] ?? false), FILTER_VALIDATE_BOOLEAN);
        $employeeControlNo = trim((string) ($validated['employee_control_no'] ?? ''));
        $rawPassword = (string) ($validated['password'] ?? '');

        if (!$isGuest && $employeeControlNo === '') {
            throw ValidationException::withMessages([
                'employee_control_no' => ['Employee is required when guest mode is off.'],
            ]);
        }

        if ($isGuest && trim($rawPassword) === '') {
            throw ValidationException::withMessages([
                'password' => ['Password is required when guest mode is on.'],
            ]);
        }

        $employee = !$isGuest
            ? $this->resolveEligibleEmployeeForAssignment($employeeControlNo)
            : null;

        $this->assertUsernameAvailableForDepartmentAdmin((string) $validated['username']);
        $generatedPassword = $isGuest
            ? trim($rawPassword)
            : $this->buildGeneratedPasswordFromBirthDate($employee);

        $admin = DepartmentAdmin::query()
            ->where('department_id', $department->id)
            ->where(function ($query): void {
                $query->whereNull('employee_control_no')
                    ->orWhereRaw("LTRIM(RTRIM(CONVERT(VARCHAR(64), employee_control_no))) = ''");
            })
            ->where(function ($query): void {
                $query->whereRaw("username LIKE 'archived_admin_%'")
                    ->orWhere('is_default_account', true);
            })
            ->orderBy('id')
            ->first();

        if (!$admin) {
            $admin = new DepartmentAdmin();
        }

        $admin->department_id = $department->id;
        $admin->is_default_account = false;
        $admin->employee_control_no = $employee ? trim((string) $employee->control_no) : null;
        $admin->full_name = $employee
            ? $this->buildEmployeeFullName($employee)
            : $this->buildGuestAdminFullName((string) $validated['username']);
        $admin->username = trim((string) $validated['username']);
        $admin->password = $generatedPassword;
        $admin->must_change_password = true;
        $admin->save();
        $admin->load([
            'department:id,name',
        ]);

        return response()->json([
            'message' => $isGuest
                ? 'Guest department admin account created successfully. Password must be changed on first login.'
                : 'Department admin assigned successfully. Default password is employee birthdate (MMDDYY).',
            'department_admin' => $this->serializeDepartmentAdmin($admin),
        ], 201);
    }

    private function storeHrAccount(array $validated): JsonResponse
    {
        $isGuest = filter_var((string) ($validated['is_guest'] ?? false), FILTER_VALIDATE_BOOLEAN);
        $employeeControlNo = trim((string) ($validated['employee_control_no'] ?? ''));
        $rawPassword = (string) ($validated['password'] ?? '');

        if (!$isGuest && $employeeControlNo === '') {
            throw ValidationException::withMessages([
                'employee_control_no' => ['Employee is required when guest mode is off.'],
            ]);
        }

        if ($isGuest && trim($rawPassword) === '') {
            throw ValidationException::withMessages([
                'password' => ['Password is required when guest mode is on.'],
            ]);
        }

        $employee = !$isGuest
            ? $this->resolveEligibleEmployeeForAssignment($employeeControlNo)
            : null;

        $this->assertUsernameAvailableForDepartmentAdmin((string) $validated['username']);
        $generatedPassword = $isGuest
            ? trim($rawPassword)
            : $this->buildGeneratedPasswordFromBirthDate($employee);

        $position = trim((string) ($employee?->designation ?? ''));
        if ($position === '') {
            $position = 'HR';
        }

        $hrAccount = HRAccount::query()->create([
            'full_name' => $employee
                ? $this->buildEmployeeFullName($employee)
                : $this->buildGuestHrFullName((string) $validated['username']),
            'position' => $position,
            'username' => trim((string) $validated['username']),
            'password' => $generatedPassword,
            'must_change_password' => true,
        ]);

        return response()->json([
            'message' => $isGuest
                ? 'Guest HR account created successfully. Password must be changed on first login.'
                : 'HR account created successfully. Default password is employee birthdate (MMDDYY).',
            'hr_account' => $this->serializeHrAccountRow($hrAccount),
        ], 201);
    }

    /**
     * Update an existing department admin assignment/account.
     */
    public function update(Request $request, int $id): JsonResponse
    {
        $admin = DepartmentAdmin::query()->find($id);
        if (!$admin) {
            return response()->json([
                'message' => 'Department admin not found.',
            ], 404);
        }

        $validated = $request->validate([
            'department_id' => [
                'required',
                'integer',
                Rule::exists('tblDepartments', 'id')->where(fn ($query) => $query->where('is_inactive', false)),
            ],
            'employee_control_no' => ['required', 'string', 'max:50', 'regex:/^\d+$/'],
            'username' => ['required', 'string', 'max:255', Rule::unique('tblDepartmentAdmins', 'username')->ignore($admin->id)],
        ]);

        $department = Department::query()->active()->find((int) $validated['department_id']);
        if (!$department) {
            return response()->json([
                'message' => 'Department not found.',
            ], 404);
        }

        $employee = $this->resolveEligibleEmployeeForAssignment((string) $validated['employee_control_no']);
        $this->assertUsernameAvailableForDepartmentAdmin(
            (string) $validated['username'],
            (int) $admin->id
        );
        $employeeChanged = trim((string) $admin->employee_control_no) !== trim((string) $employee->control_no);

        $admin->department_id = $department->id;
        $admin->employee_control_no = trim((string) $employee->control_no);
        $admin->full_name = $this->buildEmployeeFullName($employee);
        $admin->username = trim((string) $validated['username']);
        $admin->password = $this->buildGeneratedPasswordFromBirthDate($employee);
        $admin->must_change_password = true;
        $admin->save();
        $admin->load([
            'department:id,name',
        ]);

        return response()->json([
            'message' => $employeeChanged
                ? 'Department admin reassigned successfully. Default password is employee birthdate (MMDDYY).'
                : 'Department admin updated successfully. Default password is employee birthdate (MMDDYY).',
            'department_admin' => $this->serializeDepartmentAdmin($admin),
        ]);
    }

    /**
     * Reactivate an archived office admin account.
     */
    public function reactivate(Request $request, int $id): JsonResponse
    {
        $admin = DepartmentAdmin::query()->find($id);
        if (!$admin) {
            return response()->json([
                'message' => 'Department admin not found.',
            ], 404);
        }

        if ($this->isDepartmentAdminAssignmentActive($admin)) {
            return response()->json([
                'message' => 'This office admin account is already active.',
            ], 422);
        }

        $validated = $request->validate([
            'employee_control_no' => ['required', 'string', 'max:50', 'regex:/^\d+$/'],
            'username' => ['required', 'string', 'max:255', Rule::unique('tblDepartmentAdmins', 'username')->ignore($admin->id)],
        ]);

        $employee = $this->resolveEligibleEmployeeForAssignment((string) $validated['employee_control_no']);
        $this->assertUsernameAvailableForDepartmentAdmin(
            (string) $validated['username'],
            (int) $admin->id
        );

        $admin->employee_control_no = trim((string) $employee->control_no);
        $admin->full_name = $this->buildEmployeeFullName($employee);
        $admin->username = trim((string) $validated['username']);
        $admin->password = $this->buildGeneratedPasswordFromBirthDate($employee);
        $admin->must_change_password = true;
        $admin->is_default_account = false;
        $admin->save();
        $admin->load([
            'department:id,name',
        ]);

        return response()->json([
            'message' => 'Office admin account reactivated successfully. Default password is employee birthdate (MMDDYY).',
            'department_admin' => $this->serializeDepartmentAdmin($admin),
        ]);
    }

    /**
     * Reset an active office admin account password to the default (MMDDYY).
     */
    public function resetDepartmentAdminPassword(Request $request, int $id): JsonResponse
    {
        $admin = DepartmentAdmin::query()->find($id);
        if (!$admin) {
            return response()->json([
                'message' => 'Department admin not found.',
            ], 404);
        }

        if (!$this->isDepartmentAdminAssignmentActive($admin)) {
            return response()->json([
                'message' => 'Only active office admin accounts can be reset.',
            ], 422);
        }

        $employeeControlNo = trim((string) ($admin->employee_control_no ?? ''));
        if ($employeeControlNo === '') {
            return response()->json([
                'message' => 'This office admin account has no linked employee. Unable to reset default password.',
            ], 422);
        }

        $employee = HrisEmployee::findByControlNo($employeeControlNo);
        if (!$employee) {
            return response()->json([
                'message' => 'Linked employee not found in HRIS. Unable to reset default password.',
            ], 422);
        }

        $admin->password = $this->buildGeneratedPasswordFromBirthDate($employee);
        $admin->must_change_password = true;
        $admin->save();

        return response()->json([
            'message' => 'Office admin password reset successfully. Default password is employee birthdate (MMDDYY).',
        ]);
    }

    /**
     * Reset an HR account password to the default (MMDDYY).
     */
    public function resetHrAccountPassword(Request $request, int $id): JsonResponse
    {
        $hrAccount = HRAccount::query()->find($id);
        if (!$hrAccount) {
            return response()->json([
                'message' => 'HR account not found.',
            ], 404);
        }

        $employee = $this->resolveEmployeeForHrPasswordReset($hrAccount);
        $hrAccount->password = $this->buildGeneratedPasswordFromBirthDate($employee);
        $hrAccount->must_change_password = true;
        $hrAccount->save();

        return response()->json([
            'message' => 'HR account password reset successfully. Default password is employee birthdate (MMDDYY).',
        ]);
    }

    /**
     * Remove a department admin account.
     */
    public function destroy(Request $request, int $id): JsonResponse
    {
        $admin = DepartmentAdmin::query()->find($id);
        if (!$admin) {
            return response()->json([
                'message' => 'Department admin not found.',
            ], 404);
        }

        $departmentId = $admin->department_id !== null ? (int) $admin->department_id : null;
        if ($departmentId !== null) {
            $hasReplacementAdmin = DepartmentAdmin::query()
                ->where('department_id', $departmentId)
                ->where('id', '!=', $admin->id)
                ->whereNotNull('employee_control_no')
                ->whereRaw("LTRIM(RTRIM(employee_control_no)) <> ''")
                ->exists();

            if (!$hasReplacementAdmin) {
                return response()->json([
                    'message' => 'Assign a new department admin for this department before removing the current admin.',
                ], 422);
            }
        }

        if ($this->hasHistoricalLeaveApplications($admin) || $this->hasHistoricalCocApplicationsForDepartmentAdmin($admin)) {
            $this->vacateDepartmentAdminAssignment($admin);

            return response()->json([
                'message' => 'Department admin removed successfully. Historical application records were preserved.',
            ]);
        }

        $admin->loadMissing(['department:id,name']);
        $employeeSnapshot = $this->resolveEmployeeForAdmin($admin);

        DB::transaction(function () use ($admin, $request, $employeeSnapshot): void {
            app(RecycleBinService::class)->storeDeletedModel(
                $admin,
                $request->user(),
                [
                    'record_title' => $admin->full_name,
                    'delete_source' => 'hr.user-management',
                    'delete_reason' => $request->input('reason'),
                    'snapshot' => array_merge($admin->toArray(), [
                        'department' => $admin->department?->only(['id', 'name']),
                        'employee' => $employeeSnapshot ? $this->serializeEligibleEmployee($employeeSnapshot) : null,
                    ]),
                ]
            );

            $admin->delete();
        });

        return response()->json([
            'message' => 'Department admin removed successfully.',
        ]);
    }

    /**
     * Remove an HR admin account.
     */
    public function destroyHrAccount(Request $request, int $id): JsonResponse
    {
        $hrAccount = HRAccount::query()->find($id);
        if (!$hrAccount) {
            return response()->json([
                'message' => 'HR account not found.',
            ], 404);
        }

        $currentUser = $request->user();
        if ($currentUser instanceof HRAccount && (int) $currentUser->id === (int) $hrAccount->id) {
            return response()->json([
                'message' => 'You cannot remove the currently logged-in HR account.',
            ], 422);
        }

        $remainingHrAccounts = HRAccount::query()
            ->where('id', '!=', $hrAccount->id)
            ->count();
        if ($remainingHrAccounts < 1) {
            return response()->json([
                'message' => 'At least one HR account must remain.',
            ], 422);
        }

        if ($this->hasHistoricalCocApplicationsForHrAccount($hrAccount)) {
            return response()->json([
                'message' => 'This HR account has historical COC records and cannot be removed.',
            ], 422);
        }

        DB::transaction(function () use ($hrAccount, $request): void {
            app(RecycleBinService::class)->storeDeletedModel(
                $hrAccount,
                $request->user(),
                [
                    'record_title' => $hrAccount->full_name,
                    'delete_source' => 'hr.user-management',
                    'delete_reason' => $request->input('reason'),
                    'snapshot' => $hrAccount->toArray(),
                ]
            );

            $hrAccount->delete();
        });

        return response()->json([
            'message' => 'HR admin removed successfully.',
        ]);
    }

    private function resolveEligibleEmployeeForAssignment(string $employeeControlNo): object
    {
        $employee = HrisEmployee::findByControlNo($employeeControlNo, true);
        if (!$employee) {
            throw ValidationException::withMessages([
                'employee_control_no' => ['Active employee not found.'],
            ]);
        }

        return $employee;
    }

    private function assertUsernameAvailableForDepartmentAdmin(string $username, ?int $ignoreDepartmentAdminId = null): void
    {
        $normalizedUsername = trim($username);
        if ($normalizedUsername === '') {
            return;
        }

        $usernameIsUsedByHr = HRAccount::query()
            ->where('username', $normalizedUsername)
            ->exists();

        if ($usernameIsUsedByHr) {
            throw ValidationException::withMessages([
                'username' => ['The username has already been taken.'],
            ]);
        }

        $usernameIsUsedByDepartmentAdmin = DepartmentAdmin::query()
            ->where('username', $normalizedUsername)
            ->when($ignoreDepartmentAdminId !== null, function ($query) use ($ignoreDepartmentAdminId): void {
                $query->where('id', '!=', $ignoreDepartmentAdminId);
            })
            ->exists();

        if ($usernameIsUsedByDepartmentAdmin) {
            throw ValidationException::withMessages([
                'username' => ['The username has already been taken.'],
            ]);
        }
    }

    private function buildEmployeeFullName(object $employee): string
    {
        $parts = array_values(array_filter([
            trim((string) $employee->firstname),
            trim((string) $employee->middlename),
            trim((string) $employee->surname),
        ], fn(string $part): bool => $part !== ''));

        if ($parts === []) {
            return trim((string) $employee->control_no);
        }

        return trim(implode(' ', $parts));
    }

    private function buildGuestAdminFullName(string $username): string
    {
        $normalizedUsername = trim($username);
        if ($normalizedUsername === '') {
            return 'Department Admin Guest';
        }

        return Str::limit($normalizedUsername, 255, '');
    }

    private function buildGuestHrFullName(string $username): string
    {
        $normalizedUsername = trim($username);
        if ($normalizedUsername === '') {
            return 'HR Guest';
        }

        return Str::limit($normalizedUsername, 255, '');
    }

    private function buildEmployeeDisplayName(object $employee): string
    {
        $surname = trim((string) $employee->surname);
        $firstname = trim((string) $employee->firstname);
        $middlename = trim((string) $employee->middlename);

        $primary = trim($surname !== '' ? "{$surname}, {$firstname}" : "{$firstname} {$surname}");
        if ($middlename !== '') {
            $primary = trim("{$primary} {$middlename}");
        }

        return $primary !== '' ? $primary : trim((string) $employee->control_no);
    }

    private function serializeDepartmentRow(Department $department): array
    {
        $admin = $department->admin;

        return [
            'id' => $department->id,
            'name' => $department->name,
            'department_admin' => $admin && $this->isDepartmentAdminAssignmentActive($admin)
                ? $this->serializeDepartmentAdmin($admin)
                : null,
        ];
    }

    private function serializeDepartmentAdminAccountRow(DepartmentAdmin $admin): array
    {
        $isActive = $this->isDepartmentAdminAssignmentActive($admin);
        $employee = $this->resolveEmployeeForAdmin($admin);
        $position = trim((string) ($employee?->designation ?? ''));
        if ($position === '') {
            $position = 'Department Admin';
        }

        return [
            'row_key' => 'DEPARTMENT_ADMIN-' . $admin->id,
            'account_id' => $admin->id,
            'role' => 'DEPARTMENT_ADMIN',
            'role_label' => 'Department Admin',
            'full_name' => trim((string) ($admin->full_name ?? '')),
            'username' => trim((string) ($admin->username ?? '')),
            'department_id' => $admin->department_id !== null ? (int) $admin->department_id : null,
            'department' => trim((string) ($admin->department?->name ?? '')) !== ''
                ? trim((string) $admin->department?->name)
                : null,
            'position' => $position,
            'employee_control_no' => trim((string) ($admin->employee_control_no ?? '')) !== ''
                ? trim((string) $admin->employee_control_no)
                : null,
            'is_active' => $isActive,
            'can_reactivate' => !$isActive,
            'can_reset_password' => $isActive && trim((string) ($admin->employee_control_no ?? '')) !== '',
            'is_default_account' => (bool) $admin->is_default_account,
            'can_delete' => true,
            'must_change_password' => (bool) $admin->must_change_password,
            'created_at' => $admin->created_at?->toIso8601String(),
            'updated_at' => $admin->updated_at?->toIso8601String(),
        ];
    }

    private function serializeHrAccountRow(HRAccount $account): array
    {
        return [
            'row_key' => 'HR-' . $account->id,
            'account_id' => $account->id,
            'role' => 'HR',
            'role_label' => 'HR Admin',
            'full_name' => trim((string) ($account->full_name ?? '')),
            'username' => trim((string) ($account->username ?? '')),
            'department' => self::HR_DEFAULT_DEPARTMENT,
            'position' => trim((string) ($account->position ?? '')) !== ''
                ? trim((string) $account->position)
                : null,
            'employee_control_no' => null,
            'can_reset_password' => true,
            'can_delete' => true,
            'must_change_password' => (bool) $account->must_change_password,
            'created_at' => $account->created_at?->toIso8601String(),
            'updated_at' => $account->updated_at?->toIso8601String(),
        ];
    }

    private function resolveEmployeeForHrPasswordReset(HRAccount $account): object
    {
        $accountControlNo = trim((string) ($account->employee_control_no ?? ''));
        if ($accountControlNo !== '') {
            return $this->resolveEligibleEmployeeForAssignment($accountControlNo);
        }

        $targetFullName = mb_strtolower(trim((string) ($account->full_name ?? '')));
        if ($targetFullName === '') {
            throw ValidationException::withMessages([
                'employee_control_no' => ['This HR account has no employee reference. Unable to reset default password.'],
            ]);
        }

        $matches = HrisEmployee::allCached(true)
            ->filter(function (object $employee) use ($targetFullName): bool {
                return mb_strtolower($this->buildEmployeeFullName($employee)) === $targetFullName;
            })
            ->values();

        if ($matches->count() < 1) {
            throw ValidationException::withMessages([
                'employee_control_no' => ['Linked employee not found in HRIS. Unable to reset default password for this HR account.'],
            ]);
        }

        if ($matches->count() > 1) {
            throw ValidationException::withMessages([
                'employee_control_no' => ['Multiple HRIS employees match this HR account name. Unable to reset default password safely.'],
            ]);
        }

        return (object) $matches->first();
    }

    private function hasHistoricalCocApplicationsForHrAccount(HRAccount $account): bool
    {
        if (!Schema::hasTable('tblCOCApplications')) {
            return false;
        }

        $candidateColumns = [];
        foreach ([
            'reviewed_by_hr_id',
            'late_filing_reviewed_by_hr_id',
            'hr_received_by_id',
            'hr_released_by_id',
        ] as $column) {
            if (Schema::hasColumn('tblCOCApplications', $column)) {
                $candidateColumns[] = $column;
            }
        }

        if ($candidateColumns === []) {
            return false;
        }

        return DB::table('tblCOCApplications')
            ->where(function ($query) use ($candidateColumns, $account): void {
                foreach ($candidateColumns as $index => $column) {
                    if ($index === 0) {
                        $query->where($column, $account->id);
                        continue;
                    }

                    $query->orWhere($column, $account->id);
                }
            })
            ->exists();
    }

    private function isDepartmentAdminAssignmentActive(?DepartmentAdmin $admin): bool
    {
        if (!$admin) {
            return false;
        }

        return trim((string) ($admin->employee_control_no ?? '')) !== '';
    }

    private function hasHistoricalLeaveApplications(DepartmentAdmin $admin): bool
    {
        return LeaveApplication::query()
            ->where('applicant_admin_id', $admin->id)
            ->exists();
    }

    private function hasHistoricalCocApplicationsForDepartmentAdmin(DepartmentAdmin $admin): bool
    {
        if (!Schema::hasTable('tblCOCApplications')) {
            return false;
        }

        $candidateColumns = [];
        foreach ([
            'reviewed_by_admin_id',
        ] as $column) {
            if (Schema::hasColumn('tblCOCApplications', $column)) {
                $candidateColumns[] = $column;
            }
        }

        if ($candidateColumns === []) {
            return false;
        }

        return DB::table('tblCOCApplications')
            ->where(function ($query) use ($candidateColumns, $admin): void {
                foreach ($candidateColumns as $index => $column) {
                    if ($index === 0) {
                        $query->where($column, $admin->id);
                        continue;
                    }
                    $query->orWhere($column, $admin->id);
                }
            })
            ->exists();
    }

    private function vacateDepartmentAdminAssignment(DepartmentAdmin $admin): void
    {
        $archivedUsername = $this->buildArchivedDepartmentAdminUsername($admin);

        $admin->tokens()->delete();
        $admin->employee_control_no = null;
        $admin->username = $archivedUsername;
        $admin->password = Str::random(40);
        $admin->must_change_password = false;
        $admin->active_personal_access_token_id = null;
        $admin->save();
    }

    private function buildArchivedDepartmentAdminUsername(DepartmentAdmin $admin): string
    {
        $base = 'archived_admin_' . $admin->id;
        $candidate = $base;

        while (
            DepartmentAdmin::query()
                ->where('username', $candidate)
                ->where('id', '!=', $admin->id)
                ->exists()
        ) {
            $candidate = $base . '_' . Str::lower(Str::random(6));
        }

        return $candidate;
    }

    private function resolveEmployeeForAdmin(DepartmentAdmin $admin): ?object
    {
        $controlNo = trim((string) ($admin->employee_control_no ?? ''));
        if ($controlNo === '') {
            return null;
        }

        return HrisEmployee::findByControlNo($controlNo);
    }

    private function serializeDepartmentAdmin(DepartmentAdmin $admin): array
    {
        $employee = $this->resolveEmployeeForAdmin($admin);

        return [
            'id' => $admin->id,
            'department_id' => $admin->department_id,
            'employee_control_no' => $admin->employee_control_no,
            'department' => $admin->department
                ? [
                    'id' => $admin->department->id,
                    'name' => $admin->department->name,
                ]
                : null,
            'full_name' => $admin->full_name,
            'username' => $admin->username,
            'is_default_account' => (bool) $admin->is_default_account,
            'must_change_password' => (bool) $admin->must_change_password,
            'leave_initialized' => $this->resolveLeaveInitializedForEmployeeControlNo((string) $admin->employee_control_no),
            'employee' => $employee ? $this->serializeEligibleEmployee($employee) : null,
            'created_at' => $admin->created_at?->toIso8601String(),
            'updated_at' => $admin->updated_at?->toIso8601String(),
        ];
    }

    private function serializeEligibleEmployee(object $employee): array
    {
        $status = strtoupper(trim((string) ($employee->status ?? '')));

        return [
            'control_no' => trim((string) $employee->control_no),
            'surname' => trim((string) $employee->surname),
            'firstname' => trim((string) $employee->firstname),
            'middlename' => trim((string) ($employee->middlename ?? '')),
            'full_name' => $this->buildEmployeeDisplayName($employee),
            'birth_date' => $employee->birth_date instanceof \DateTimeInterface
                ? $employee->birth_date->format('Y-m-d')
                : trim((string) ($employee->birth_date ?? '')),
            'office' => trim((string) $employee->office),
            'officeAcronym' => trim((string) ($employee->officeAcronym ?? '')),
            'office_acronym' => trim((string) ($employee->officeAcronym ?? '')),
            'hrisOfficeAcronym' => trim((string) ($employee->hrisOfficeAcronym ?? '')),
            'hris_office_acronym' => trim((string) ($employee->hrisOfficeAcronym ?? '')),
            'status' => $status,
            'designation' => trim((string) ($employee->designation ?? '')),
        ];
    }

    private function buildGeneratedPasswordFromBirthDate(object $employee): string
    {
        $birthDate = $this->resolveEmployeeBirthDate($employee);
        if (!$birthDate) {
            throw ValidationException::withMessages([
                'employee_control_no' => ['Selected employee has no birth date in HRIS. Cannot generate default password.'],
            ]);
        }

        return $birthDate->format('mdy');
    }

    private function resolveEmployeeBirthDate(object $employee): ?\DateTimeInterface
    {
        $value = $employee->birth_date;

        if ($value instanceof \DateTimeInterface) {
            return $value;
        }

        $text = trim((string) ($value ?? ''));
        if ($text === '') {
            return null;
        }

        $date = date_create($text);

        return $date === false ? null : $date;
    }

    private function resolveLeaveInitializedForEmployeeControlNo(string $employeeControlNo): bool
    {
        $employeeControlNo = trim($employeeControlNo);
        if ($employeeControlNo === '') {
            return false;
        }

        $candidateEmployeeControlNos = [$employeeControlNo];
        $normalizedControlNo = ltrim($employeeControlNo, '0');
        if ($normalizedControlNo === '') {
            $normalizedControlNo = '0';
        }
        $candidateEmployeeControlNos[] = $normalizedControlNo;

        $employee = HrisEmployee::findByControlNo($employeeControlNo);
        if ($employee && trim((string) $employee->control_no) !== '') {
            $candidateEmployeeControlNos[] = trim((string) $employee->control_no);
        }

        $candidateEmployeeControlNos = array_values(array_unique(array_filter(
            $candidateEmployeeControlNos,
            static fn(string $value): bool => $value !== ''
        )));

        return LeaveBalance::query()
            ->whereIn('employee_control_no', $candidateEmployeeControlNos)
            ->exists();
    }
}
