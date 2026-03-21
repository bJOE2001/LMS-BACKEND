<?php

namespace App\Http\Controllers;

use App\Models\Department;
use App\Models\DepartmentAdmin;
use App\Models\Employee;
use App\Models\HRAccount;
use App\Models\LeaveBalance;
use App\Models\LeaveApplication;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class HRUserManagementController extends Controller
{
    /**
     * List all departments and current department admin assignments.
     */
    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'search' => ['nullable', 'string', 'max:100'],
        ]);

        $searchTerm = trim((string) ($validated['search'] ?? ''));

        $departments = Department::query()
            ->with([
                'admin:id,department_id,employee_control_no,full_name,username,must_change_password,created_at,updated_at',
                'admin.employee:control_no,surname,firstname,middlename,birth_date,office,status,designation',
            ])
            ->when($searchTerm !== '', function ($query) use ($searchTerm): void {
                $query->where(function ($nestedQuery) use ($searchTerm): void {
                    $nestedQuery
                        ->where('name', 'LIKE', "%{$searchTerm}%")
                        ->orWhereHas('admin', function ($adminQuery) use ($searchTerm): void {
                            $adminQuery
                                ->where('full_name', 'LIKE', "%{$searchTerm}%")
                                ->orWhere('username', 'LIKE', "%{$searchTerm}%")
                                ->orWhere('employee_control_no', 'LIKE', "%{$searchTerm}%");
                        });
                });
            })
            ->orderBy('name')
            ->get()
            ->map(fn(Department $department): array => $this->serializeDepartmentRow($department))
            ->values();

        $assignedDepartments = $departments
            ->filter(fn(array $department): bool => $department['department_admin'] !== null)
            ->count();

        return response()->json([
            'departments' => $departments,
            'summary' => [
                'total_departments' => $departments->count(),
                'assigned_departments' => $assignedDepartments,
                'unassigned_departments' => $departments->count() - $assignedDepartments,
            ],
        ]);
    }

    /**
     * List eligible employees for a department (excluding CONTRACTUAL).
     */
    public function eligibleEmployees(Request $request, int $departmentId): JsonResponse
    {
        $validated = $request->validate([
            'search' => ['nullable', 'string', 'max:100'],
        ]);

        $department = Department::query()->find($departmentId);
        if (!$department) {
            return response()->json([
                'message' => 'Department not found.',
            ], 404);
        }

        $searchTerm = trim((string) ($validated['search'] ?? ''));

        $employees = Employee::query()
            ->select([
                'control_no',
                'surname',
                'firstname',
                'middlename',
                'birth_date',
                'office',
                'status',
                'designation',
            ])
            ->where('office', $department->name)
            ->where(function ($query): void {
                $query->whereNull('status')
                    ->orWhereRaw("UPPER(LTRIM(RTRIM(status))) <> 'CONTRACTUAL'");
            })
            ->when($searchTerm !== '', function ($query) use ($searchTerm): void {
                $query->where(function ($nestedQuery) use ($searchTerm): void {
                    $nestedQuery
                        ->where('control_no', 'LIKE', "%{$searchTerm}%")
                        ->orWhere('surname', 'LIKE', "%{$searchTerm}%")
                        ->orWhere('firstname', 'LIKE', "%{$searchTerm}%")
                        ->orWhere('middlename', 'LIKE', "%{$searchTerm}%")
                        ->orWhere('designation', 'LIKE', "%{$searchTerm}%");
                });
            })
            ->orderBy('surname')
            ->orderBy('firstname')
            ->get()
            ->map(fn(Employee $employee): array => $this->serializeEligibleEmployee($employee))
            ->values();

        return response()->json([
            'department' => [
                'id' => $department->id,
                'name' => $department->name,
            ],
            'employees' => $employees,
        ]);
    }

    /**
     * Assign a department admin account from tblEmployees to a department.
     */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'department_id' => ['required', 'integer', 'exists:tblDepartments,id'],
            'employee_control_no' => ['required', 'string', 'max:50', 'regex:/^\d+$/'],
            'username' => ['required', 'string', 'max:255', Rule::unique('tblDepartmentAdmins', 'username')],
        ]);

        $department = Department::query()->find((int) $validated['department_id']);
        if (!$department) {
            return response()->json([
                'message' => 'Department not found.',
            ], 404);
        }

        $employee = $this->resolveEligibleEmployeeForDepartment(
            $department,
            (string) $validated['employee_control_no']
        );
        $this->assertUsernameAvailableForDepartmentAdmin((string) $validated['username']);
        $generatedPassword = $this->buildGeneratedPasswordFromBirthDate($employee);

        $existingAdmin = DepartmentAdmin::query()
            ->where('department_id', $department->id)
            ->first();

        if ($existingAdmin && $this->isDepartmentAdminAssignmentActive($existingAdmin)) {
            throw ValidationException::withMessages([
                'department_id' => ['Selected department already has an assigned admin.'],
            ]);
        }

        $admin = $existingAdmin ?? new DepartmentAdmin();
        $admin->department_id = $department->id;
        $admin->employee_control_no = trim((string) $employee->control_no);
        $admin->full_name = $this->buildEmployeeFullName($employee);
        $admin->username = trim((string) $validated['username']);
        $admin->password = $generatedPassword;
        $admin->must_change_password = true;
        $admin->save();
        $admin->load([
            'department:id,name',
            'employee:control_no,surname,firstname,middlename,birth_date,office,status,designation',
        ]);

        return response()->json([
            'message' => 'Department admin assigned successfully. Default password is employee birthdate (MMDDYY).',
            'department_admin' => $this->serializeDepartmentAdmin($admin),
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
            'department_id' => ['required', 'integer', 'exists:tblDepartments,id', Rule::unique('tblDepartmentAdmins', 'department_id')->ignore($admin->id)],
            'employee_control_no' => ['required', 'string', 'max:50', 'regex:/^\d+$/'],
            'username' => ['required', 'string', 'max:255', Rule::unique('tblDepartmentAdmins', 'username')->ignore($admin->id)],
        ]);

        $department = Department::query()->find((int) $validated['department_id']);
        if (!$department) {
            return response()->json([
                'message' => 'Department not found.',
            ], 404);
        }

        $employee = $this->resolveEligibleEmployeeForDepartment(
            $department,
            (string) $validated['employee_control_no']
        );
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
            'employee:control_no,surname,firstname,middlename,birth_date,office,status,designation',
        ]);

        return response()->json([
            'message' => $employeeChanged
                ? 'Department admin reassigned successfully. Default password is employee birthdate (MMDDYY).'
                : 'Department admin updated successfully. Default password is employee birthdate (MMDDYY).',
            'department_admin' => $this->serializeDepartmentAdmin($admin),
        ]);
    }

    /**
     * Remove a department admin account.
     */
    public function destroy(int $id): JsonResponse
    {
        $admin = DepartmentAdmin::query()->find($id);
        if (!$admin) {
            return response()->json([
                'message' => 'Department admin not found.',
            ], 404);
        }

        if ($this->hasHistoricalLeaveApplications($admin)) {
            $this->vacateDepartmentAdminAssignment($admin);

            return response()->json([
                'message' => 'Department admin removed successfully. Historical leave applications were preserved.',
            ]);
        }

        $admin->delete();

        return response()->json([
            'message' => 'Department admin removed successfully.',
        ]);
    }

    private function resolveEligibleEmployeeForDepartment(Department $department, string $employeeControlNo): Employee
    {
        $employee = Employee::findByControlNo($employeeControlNo);
        if (!$employee) {
            throw ValidationException::withMessages([
                'employee_control_no' => ['Employee not found.'],
            ]);
        }

        $employeeStatus = strtoupper(trim((string) ($employee->status ?? '')));
        if ($employeeStatus === 'CONTRACTUAL') {
            throw ValidationException::withMessages([
                'employee_control_no' => ['Contractual employees cannot be assigned as department admin.'],
            ]);
        }

        if (!$this->sameOffice($employee->office, $department->name)) {
            throw ValidationException::withMessages([
                'employee_control_no' => ['Selected employee does not belong to the selected department.'],
            ]);
        }

        return $employee;
    }

    private function sameOffice(mixed $left, mixed $right): bool
    {
        return $this->normalizeOffice($left) === $this->normalizeOffice($right);
    }

    private function normalizeOffice(mixed $value): string
    {
        $normalized = preg_replace('/\s+/', ' ', trim((string) $value));
        return strtoupper($normalized ?? '');
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

    private function buildEmployeeFullName(Employee $employee): string
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

    private function buildEmployeeDisplayName(Employee $employee): string
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

    private function vacateDepartmentAdminAssignment(DepartmentAdmin $admin): void
    {
        $archivedUsername = $this->buildArchivedDepartmentAdminUsername($admin);

        $admin->employee_control_no = null;
        $admin->username = $archivedUsername;
        $admin->password = Str::random(40);
        $admin->must_change_password = false;
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

    private function serializeDepartmentAdmin(DepartmentAdmin $admin): array
    {
        $employee = $admin->employee;

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
            'must_change_password' => (bool) $admin->must_change_password,
            'leave_initialized' => $this->resolveLeaveInitializedForEmployeeControlNo((string) $admin->employee_control_no),
            'employee' => $employee ? $this->serializeEligibleEmployee($employee) : null,
            'created_at' => $admin->created_at?->toIso8601String(),
            'updated_at' => $admin->updated_at?->toIso8601String(),
        ];
    }

    private function serializeEligibleEmployee(Employee $employee): array
    {
        $status = strtoupper(trim((string) ($employee->status ?? '')));

        return [
            'control_no' => trim((string) $employee->control_no),
            'surname' => trim((string) $employee->surname),
            'firstname' => trim((string) $employee->firstname),
            'middlename' => trim((string) $employee->middlename) !== '' ? trim((string) $employee->middlename) : null,
            'full_name' => $this->buildEmployeeDisplayName($employee),
            'birth_date' => $employee->birth_date instanceof \DateTimeInterface
                ? $employee->birth_date->format('Y-m-d')
                : (trim((string) ($employee->birth_date ?? '')) !== '' ? trim((string) $employee->birth_date) : null),
            'office' => trim((string) $employee->office),
            'status' => $status !== '' ? $status : null,
            'designation' => trim((string) $employee->designation) !== '' ? trim((string) $employee->designation) : null,
        ];
    }

    private function buildGeneratedPasswordFromBirthDate(Employee $employee): string
    {
        $birthDate = $this->resolveEmployeeBirthDate($employee);
        if (!$birthDate) {
            throw ValidationException::withMessages([
                'employee_control_no' => ['Selected employee has no birth date in HRIS. Cannot generate default password.'],
            ]);
        }

        return $birthDate->format('mdy');
    }

    private function resolveEmployeeBirthDate(Employee $employee): ?\DateTimeInterface
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

        $candidateEmployeeIds = [$employeeControlNo];
        $normalizedControlNo = ltrim($employeeControlNo, '0');
        if ($normalizedControlNo === '') {
            $normalizedControlNo = '0';
        }
        $candidateEmployeeIds[] = $normalizedControlNo;

        $employee = Employee::findByControlNo($employeeControlNo);
        if ($employee && trim((string) $employee->control_no) !== '') {
            $candidateEmployeeIds[] = trim((string) $employee->control_no);
        }

        $candidateEmployeeIds = array_values(array_unique(array_filter(
            $candidateEmployeeIds,
            static fn(string $value): bool => $value !== ''
        )));

        return LeaveBalance::query()
            ->whereIn('employee_id', $candidateEmployeeIds)
            ->exists();
    }
}
