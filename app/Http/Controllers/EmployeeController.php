<?php

namespace App\Http\Controllers;

use App\Models\Department;
use App\Models\DepartmentHead;
use App\Models\Employee;
use App\Models\EmployeeAccount;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;

/**
 * Employee Management — uses local LMS_DB only (departments, department_heads, employees).
 */
class EmployeeController extends Controller
{
    /**
     * List departments for the filter dropdown.
     */
    public function departments(): JsonResponse
    {
        $departments = Department::query()
            ->orderBy('name')
            ->get(['id', 'name']);

        return response()->json([
            'departments' => $departments,
        ]);
    }

    /**
     * List department heads and (when department selected) paginated employees.
     *
     * @queryParam department_id int  Filter by department. Optional; required for employees.
     * @queryParam search        string  Search employees by name. Optional.
     * @queryParam per_page     int  Items per page (default 15, max 100). Optional.
     * @queryParam page         int  Page number. Optional.
     */
    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'department_id' => ['nullable', 'integer', 'exists:departments,id'],
            'search'        => ['nullable', 'string', 'max:100'],
            'per_page'      => ['nullable', 'integer', 'min:1', 'max:100'],
            'page'          => ['nullable', 'integer', 'min:1'],
        ]);

        $departmentId = $validated['department_id'] ?? null;
        $searchTerm   = $validated['search'] ?? null;
        $perPage      = $validated['per_page'] ?? 15;

        // Department heads from local department_heads (with department name)
        $departmentHeadsQuery = DepartmentHead::query()
            ->with('department:id,name')
            ->orderBy('id');

        if ($departmentId) {
            $departmentHeadsQuery->where('department_id', $departmentId);
        }

        $departmentHeads = $departmentHeadsQuery->get()->map(function (DepartmentHead $head) {
            return [
                'id'           => $head->id,
                'department_id' => $head->department_id,
                'full_name'    => $head->full_name,
                'position'     => $head->position,
                'department'   => $head->department ? ['id' => $head->department->id, 'name' => $head->department->name] : null,
            ];
        });

        // Employees from local employees table — only when a department is selected
        $employees = null;
        if ($departmentId) {
            $employees = Employee::query()
                ->with('department:id,name')
                ->where('department_id', $departmentId)
                ->when($searchTerm, function ($query, $term) {
                    $query->where(function ($q) use ($term) {
                        $q->where('first_name', 'LIKE', "%{$term}%")
                            ->orWhere('last_name', 'LIKE', "%{$term}%");
                    });
                })
                ->orderBy('last_name')
                ->orderBy('first_name')
                ->paginate($perPage);

            $employeeIds = $employees->pluck('id')->toArray();
            $existingAccounts = EmployeeAccount::whereIn('employee_id', $employeeIds)
                ->pluck('employee_id')
                ->flip()
                ->toArray();

            $employees->getCollection()->transform(function (Employee $emp) use ($existingAccounts) {
                return [
                    'id'            => $emp->id,
                    'department_id' => $emp->department_id,
                    'first_name'    => $emp->first_name,
                    'last_name'     => $emp->last_name,
                    'birthdate'     => $emp->birthdate?->format('Y-m-d'),
                    'position'      => $emp->position,
                    'status'        => $emp->status,
                    'department'    => $emp->department ? ['id' => $emp->department->id, 'name' => $emp->department->name] : null,
                    'has_account'   => isset($existingAccounts[$emp->id]),
                ];
            });
        }

        // Total employees: all when no filter, or count for selected department (with same search)
        $totalEmployeesQuery = Employee::query();
        if ($departmentId) {
            $totalEmployeesQuery->where('department_id', $departmentId);
        }
        if ($searchTerm) {
            $totalEmployeesQuery->where(function ($q) use ($searchTerm) {
                $q->where('first_name', 'LIKE', "%{$searchTerm}%")
                    ->orWhere('last_name', 'LIKE', "%{$searchTerm}%");
            });
        }
        $totalEmployees = $totalEmployeesQuery->count();

        return response()->json([
            'department_heads' => $departmentHeads,
            'employees'        => $employees,
            'total_employees'  => $totalEmployees,
        ]);
    }

    /**
     * Generate login credentials for an employee and store in employee_accounts.
     * username = MMDDYY (e.g. 042585), default password = lastname + MMDDYY (e.g. Bernhard042585).
     * Employee must have birthdate set. First login will require password change.
     */
    public function generateCredentials(Request $request, Employee $employee): JsonResponse
    {
        $employee->loadMissing('department');

        if (! $employee->birthdate) {
            return response()->json([
                'message' => 'Employee must have a birthdate set before generating credentials.',
            ], 422);
        }

        $usernamePart = $employee->birthdate->format('mdy'); // month, day, 2-digit year e.g. 042585
        $username    = $usernamePart;
        $plainPassword = $employee->last_name . $usernamePart;

        $existing = EmployeeAccount::where('employee_id', $employee->id)->first();
        if ($existing) {
            $existing->update([
                'password'             => Hash::make($plainPassword),
                'must_change_password' => true,
            ]);
            return response()->json([
                'message'  => 'Credentials regenerated. Employee must change password on next login.',
                'username' => $username,
                'password' => $plainPassword,
            ]);
        }

        EmployeeAccount::create([
            'employee_id'          => $employee->id,
            'username'             => $username,
            'password'             => Hash::make($plainPassword),
            'must_change_password' => true,
        ]);

        return response()->json([
            'message'  => 'Credentials generated. Employee must change password on first login.',
            'username' => $username,
            'password' => $plainPassword,
        ], 201);
    }
}