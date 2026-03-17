<?php

namespace App\Http\Controllers;

use App\Models\Department;
use App\Models\DepartmentAdmin;
use App\Models\DepartmentHead;
use App\Models\Employee;
use App\Models\HRAccount;
use App\Models\LeaveApplication;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Employee Management - uses local LMS_DB only.
 *
 * Employees are filtered by `office` (string) using the selected department name.
 */
class EmployeeController extends Controller
{
    private const ALLOWED_STATUSES = [
        'CASUAL',
        'CO-TERMINOUS',
        'CONTRACTUAL',
        'ELECTIVE',
        'REGULAR',
    ];

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
     * Get the current department head for the authenticated department admin.
     */
    public function departmentHead(Request $request): JsonResponse
    {
        $admin = $this->resolveDepartmentAdmin($request);
        if ($admin instanceof JsonResponse) {
            return $admin;
        }

        $departmentHead = DepartmentHead::query()
            ->where('department_id', $admin->department_id)
            ->first();

        return response()->json([
            'department_head' => $departmentHead ? $this->serializeDepartmentHead($departmentHead) : null,
        ]);
    }

    /**
     * Create or update the current department head for the authenticated department admin.
     * POST creates a new head and rejects a second head for the same department.
     * PUT updates the existing head record.
     */
    public function upsertDepartmentHead(Request $request): JsonResponse
    {
        $admin = $this->resolveDepartmentAdmin($request);
        if ($admin instanceof JsonResponse) {
            return $admin;
        }

        $validated = $this->validateDepartmentHeadPayload($request);
        $attributes = $this->normalizeDepartmentHeadPayload($validated, $admin->department->name);
        $fullName = $this->buildDepartmentHeadFullName($attributes);
        $existingDepartmentHead = DepartmentHead::query()
            ->where('department_id', $admin->department_id)
            ->first();
        $payload = array_merge($attributes, [
            'department_id' => $admin->department_id,
            'full_name' => $fullName,
            'position' => $attributes['designation'],
        ]);

        if ($request->isMethod('post')) {
            if ($existingDepartmentHead) {
                return response()->json([
                    'message' => 'A department head already exists for this department. Edit or remove the current record first.',
                    'department_head' => $this->serializeDepartmentHead($existingDepartmentHead),
                ], 422);
            }

            $departmentHead = DepartmentHead::query()->create($payload);
            $this->syncDepartmentHeadToEmployeeRecord($departmentHead);

            return response()->json([
                'message' => 'Department head added successfully.',
                'department_head' => $this->serializeDepartmentHead($departmentHead),
            ], 201);
        }

        if (!$existingDepartmentHead) {
            return response()->json([
                'message' => 'Department head not found.',
            ], 404);
        }

        $existingDepartmentHead->fill($payload);
        $existingDepartmentHead->save();
        $this->syncDepartmentHeadToEmployeeRecord($existingDepartmentHead);

        return response()->json([
            'message' => 'Department head updated successfully.',
            'department_head' => $this->serializeDepartmentHead($existingDepartmentHead),
        ]);
    }

    /**
     * Delete the current department head for the authenticated department admin.
     */
    public function deleteDepartmentHead(Request $request): JsonResponse
    {
        $admin = $this->resolveDepartmentAdmin($request);
        if ($admin instanceof JsonResponse) {
            return $admin;
        }

        $departmentHead = DepartmentHead::query()
            ->where('department_id', $admin->department_id)
            ->first();

        if (!$departmentHead) {
            return response()->json([
                'message' => 'Department head not found.',
            ], 404);
        }

        $departmentHead->delete();

        return response()->json([
            'message' => 'Department head removed successfully.',
        ]);
    }

    /**
     * List paginated employees.
     *
     * @queryParam department_id int    Optional department filter.
     * @queryParam search string        Optional employee name search.
     * @queryParam per_page int         Items per page (default 15, max 100).
     * @queryParam page int             Page number.
     */
    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'department_id' => ['nullable', 'integer', 'exists:tblDepartments,id'],
            'search' => ['nullable', 'string', 'max:100'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $account = $request->user();
        $isHrAccount = $account instanceof HRAccount;
        $isDepartmentAdmin = $account instanceof DepartmentAdmin;

        if (!$isHrAccount && !$isDepartmentAdmin) {
            return response()->json([
                'message' => 'Only HR and department admin accounts can access this resource.',
            ], 403);
        }

        $departmentId = $validated['department_id'] ?? null;
        $searchTerm = $validated['search'] ?? null;
        $perPage = max(1, min(100, (int) ($validated['per_page'] ?? 15)));
        $page = max(1, (int) $request->input('page', 1));

        $departmentName = null;
        $summaryDepartmentId = null;
        if ($isDepartmentAdmin) {
            $account->loadMissing('department');

            if (!$account->department_id || !$account->department?->name) {
                return response()->json([
                    'message' => 'Department admin account is not assigned to a department.',
                ], 403);
            }

            if ($departmentId !== null && (int) $departmentId !== (int) $account->department_id) {
                return response()->json([
                    'message' => 'You can only access employees from your assigned department.',
                ], 403);
            }

            // Enforce tenant boundary: department admins are always scoped to their own department.
            $departmentName = $account->department->name;
            $summaryDepartmentId = (int) $account->department_id;
        } elseif ($departmentId) {
            $departmentName = Department::find($departmentId)?->name;
            $summaryDepartmentId = $departmentName ? (int) $departmentId : null;
        }

        $departmentHead = null;
        $departmentHeadLookup = [];
        if ($summaryDepartmentId) {
            $departmentHead = DepartmentHead::query()
                ->where('department_id', $summaryDepartmentId)
                ->first();
            $departmentHeadControlNo = trim((string) ($departmentHead?->control_no ?? ''));
            $departmentHeadLookup = $departmentHead && $departmentHeadControlNo !== ''
                ? [$departmentHeadControlNo => $departmentHead]
                : [];
        } elseif ($isHrAccount) {
            $departmentHeadLookup = $this->buildDepartmentHeadLookup(null, $departmentName);
        }

        if ($isHrAccount) {
            [$employees, $totalEmployees, $statusCounts] = $this->buildHrEmployeeListing(
                $request,
                $departmentName,
                $summaryDepartmentId,
                $searchTerm,
                $perPage,
                $page,
                $departmentHeadLookup
            );
        } else {
            $employees = $this->buildDepartmentAdminEmployeePaginator(
                $departmentName,
                $searchTerm,
                $perPage,
                $page,
                $departmentHeadLookup
            );
            $totalEmployees = $employees->total();
            $statusCounts = $this->buildDepartmentAdminStatusCounts($departmentName, $searchTerm);

            if (
                $departmentHead
                && $this->shouldIncludeDepartmentHeadInEmployeeSummary(
                    $departmentHead,
                    $departmentName,
                    $searchTerm,
                    false
                )
            ) {
                $totalEmployees++;

                $statusKey = strtoupper(trim((string) ($departmentHead->status ?? '')));
                if ($statusKey !== '') {
                    $statusCounts[$statusKey] = ((int) ($statusCounts[$statusKey] ?? 0)) + 1;
                }
            }
        }

        return response()->json([
            'employees' => $employees,
            'total_employees' => $totalEmployees,
            'status_counts' => $statusCounts,
            'department_head' => $departmentHead ? $this->serializeDepartmentHead($departmentHead) : null,
        ]);
    }

    /**
     * Create an employee in LMS_DB.
     * Department admins can only create employees inside their own department.
     */
    public function store(Request $request): JsonResponse
    {
        $admin = $this->resolveDepartmentAdmin($request);
        if ($admin instanceof JsonResponse) {
            return $admin;
        }

        $validated = $this->validateEmployeePayload($request);
        $attributes = $this->normalizeEmployeePayload($validated, $admin->department->name, true);

        $employee = Employee::query()->create($attributes);

        return response()->json([
            'message' => 'Employee created successfully.',
            'employee' => $this->serializeEmployee($employee),
        ], 201);
    }

    /**
     * Update an employee in LMS_DB.
     * Department admins can only update employees inside their own department.
     */
    public function update(Request $request, string $controlNo): JsonResponse
    {
        $admin = $this->resolveDepartmentAdmin($request);
        if ($admin instanceof JsonResponse) {
            return $admin;
        }

        $employee = Employee::findByControlNo($controlNo);
        if (!$employee) {
            $departmentHead = DepartmentHead::query()
                ->where('control_no', $controlNo)
                ->first();

            if (!$departmentHead) {
                return response()->json(['message' => 'Employee not found.'], 404);
            }

            return response()->json([
                'employee' => $this->serializeDepartmentHeadAsEmployee($departmentHead),
                'applications' => [],
            ]);
        }

        if (trim((string) $employee->office) !== trim((string) $admin->department->name)) {
            return response()->json([
                'message' => 'You can only update employees in your assigned department.',
            ], 403);
        }

        $validated = $this->validateEmployeePayload($request, true);
        $attributes = $this->normalizeEmployeePayload($validated, $admin->department->name, false);

        $employee->fill($attributes);
        $employee->save();

        return response()->json([
            'message' => 'Employee updated successfully.',
            'employee' => $this->serializeEmployee($employee),
        ]);
    }

    /**
     * Delete an employee in LMS_DB.
     * Department admins can only delete employees inside their own department.
     */
    public function destroy(Request $request, string $controlNo): JsonResponse
    {
        $admin = $this->resolveDepartmentAdmin($request);
        if ($admin instanceof JsonResponse) {
            return $admin;
        }

        $employee = Employee::findByControlNo($controlNo);
        if (!$employee) {
            return response()->json(['message' => 'Employee not found.'], 404);
        }

        if (trim((string) $employee->office) !== trim((string) $admin->department->name)) {
            return response()->json([
                'message' => 'You can only delete employees in your assigned department.',
            ], 403);
        }

        $employee->delete();

        return response()->json([
            'message' => 'Employee deleted successfully.',
        ]);
    }

    /**
     * Return leave application history for one employee (HR only).
     */
    public function leaveHistory(Request $request, string $controlNo): JsonResponse
    {
        $account = $request->user();
        if (!$account instanceof HRAccount) {
            return response()->json(['message' => 'Only HR accounts can access this endpoint.'], 403);
        }

        $employee = Employee::findByControlNo($controlNo);
        if (!$employee) {
            return response()->json(['message' => 'Employee not found.'], 404);
        }

        $applications = LeaveApplication::with(['leaveType'])
            ->where('erms_control_no', (string) $employee->control_no)
            ->orderByDesc('created_at')
            ->get()
            ->map(function (LeaveApplication $application) {
                return [
                    'id' => $application->id,
                    'leave_type' => $application->leaveType?->name ?? 'Unknown',
                    'start_date' => $application->start_date
                        ? Carbon::parse($application->start_date)->toDateString()
                        : null,
                    'end_date' => $application->end_date
                        ? Carbon::parse($application->end_date)->toDateString()
                        : null,
                    'total_days' => (float) $application->total_days,
                    'status' => $this->statusLabel($application->status),
                    'raw_status' => $application->status,
                    'reason' => $application->reason,
                    'remarks' => $application->remarks,
                    'date_filed' => $application->created_at?->toDateString(),
                    'admin_approved_at' => $application->admin_approved_at?->toIso8601String(),
                    'hr_approved_at' => $application->hr_approved_at?->toIso8601String(),
                    'is_monetization' => (bool) $application->is_monetization,
                ];
            })
            ->values();

        return response()->json([
            'employee' => [
                'control_no' => $employee->control_no,
                'firstname' => $employee->firstname,
                'surname' => $employee->surname,
                'middlename' => $employee->middlename,
                'office' => $employee->office,
                'designation' => $employee->designation,
                'status' => $employee->status,
            ],
            'applications' => $applications,
        ]);
    }

    private function statusLabel(?string $status): string
    {
        return match ($status) {
            LeaveApplication::STATUS_PENDING_ADMIN => 'Pending Admin',
            LeaveApplication::STATUS_PENDING_HR => 'Pending HR',
            LeaveApplication::STATUS_APPROVED => 'Approved',
            LeaveApplication::STATUS_REJECTED => 'Rejected',
            default => (string) $status,
        };
    }

    /**
     * Ensure employee write endpoints are accessed only by a department admin
     * with a valid assigned department.
     */
    private function resolveDepartmentAdmin(Request $request): DepartmentAdmin|JsonResponse
    {
        $account = $request->user();
        if (!$account instanceof DepartmentAdmin) {
            return response()->json([
                'message' => 'Only department admin accounts can manage employees.',
            ], 403);
        }

        $account->loadMissing('department');
        if (!$account->department_id || !$account->department?->name) {
            return response()->json([
                'message' => 'Department admin account is not assigned to a department.',
            ], 403);
        }

        return $account;
    }

    /**
     * Validate incoming employee payload against HRIS-aligned field constraints.
     */
    private function validateEmployeePayload(
        Request $request,
        bool $isUpdate = false
    ): array {
        if ($request->has('status')) {
            $request->merge([
                'status' => strtoupper(trim((string) $request->input('status'))),
            ]);
        }

        if ($request->has('control_no')) {
            $request->merge([
                'control_no' => trim((string) $request->input('control_no')),
            ]);
        }

        $rules = [
            'surname' => ['required', 'string', 'max:255'],
            'firstname' => ['required', 'string', 'max:255'],
            'middlename' => ['nullable', 'string', 'max:255'],
            'status' => ['required', 'string', Rule::in(self::ALLOWED_STATUSES)],
            'designation' => ['nullable', 'string', 'max:255'],
            'rate_mon' => ['nullable', 'numeric', 'min:0'],
        ];

        if (!$isUpdate) {
            $rules['control_no'] = [
                'required',
                'string',
                'max:50',
                'regex:/^\d+$/',
                Rule::unique('tblEmployees', 'control_no'),
            ];
        }

        return $request->validate($rules);
    }

    /**
     * Normalize and map validated employee payload for tblEmployees writes.
     */
    private function normalizeEmployeePayload(array $validated, string $office, bool $includeControlNo): array
    {
        $attributes = [
            'surname' => trim((string) $validated['surname']),
            'firstname' => trim((string) $validated['firstname']),
            'middlename' => $this->trimNullable($validated['middlename'] ?? null),
            'office' => $office,
            'status' => strtoupper(trim((string) $validated['status'])),
            'designation' => $this->trimNullable($validated['designation'] ?? null),
            'rate_mon' => array_key_exists('rate_mon', $validated) && $validated['rate_mon'] !== null
                ? round((float) $validated['rate_mon'], 2)
                : null,
        ];

        if ($includeControlNo) {
            $attributes['control_no'] = trim((string) $validated['control_no']);
        }

        return $attributes;
    }

    /**
     * Validate incoming department head payload using the same fields as Add Employee.
     */
    private function validateDepartmentHeadPayload(Request $request): array
    {
        if ($request->has('status')) {
            $request->merge([
                'status' => strtoupper(trim((string) $request->input('status'))),
            ]);
        }

        if ($request->has('control_no')) {
            $request->merge([
                'control_no' => trim((string) $request->input('control_no')),
            ]);
        }

        return $request->validate([
            'control_no' => ['required', 'string', 'max:50', 'regex:/^\d+$/'],
            'surname' => ['required', 'string', 'max:255'],
            'firstname' => ['required', 'string', 'max:255'],
            'middlename' => ['nullable', 'string', 'max:255'],
            'status' => ['required', 'string', Rule::in(self::ALLOWED_STATUSES)],
            'designation' => ['nullable', 'string', 'max:255'],
            'rate_mon' => ['nullable', 'numeric', 'min:0'],
        ]);
    }

    /**
     * Normalize and map validated department head payload for tblDepartmentHeads writes.
     */
    private function normalizeDepartmentHeadPayload(array $validated, string $office): array
    {
        return [
            'control_no' => trim((string) $validated['control_no']),
            'surname' => trim((string) $validated['surname']),
            'firstname' => trim((string) $validated['firstname']),
            'middlename' => $this->trimNullable($validated['middlename'] ?? null),
            'office' => $office,
            'status' => strtoupper(trim((string) $validated['status'])),
            'designation' => $this->trimNullable($validated['designation'] ?? null),
            'rate_mon' => array_key_exists('rate_mon', $validated) && $validated['rate_mon'] !== null
                ? round((float) $validated['rate_mon'], 2)
                : null,
        ];
    }

    /**
     * Shared serializer for employee payloads used by API responses.
     */
    private function serializeEmployee(Employee $employee): array
    {
        return [
            'control_no' => $employee->control_no,
            'firstname' => $employee->firstname,
            'surname' => $employee->surname,
            'middlename' => $employee->middlename,
            'designation' => $employee->designation,
            'office' => $employee->office,
            'status' => $employee->status,
            'rate_mon' => $employee->rate_mon !== null ? (float) $employee->rate_mon : null,
        ];
    }

    private function serializeDepartmentHead(DepartmentHead $departmentHead): array
    {
        return [
            'id' => $departmentHead->id,
            'department_id' => $departmentHead->department_id,
            'control_no' => $departmentHead->control_no,
            'surname' => $departmentHead->surname,
            'firstname' => $departmentHead->firstname,
            'middlename' => $departmentHead->middlename,
            'office' => $departmentHead->office,
            'status' => $departmentHead->status,
            'designation' => $departmentHead->designation,
            'rate_mon' => $departmentHead->rate_mon !== null ? (float) $departmentHead->rate_mon : null,
            'full_name' => $departmentHead->full_name,
            'position' => $departmentHead->position,
        ];
    }

    private function serializeDepartmentHeadAsEmployee(DepartmentHead $departmentHead): array
    {
        return array_merge($this->serializeDepartmentHead($departmentHead), [
            'has_account' => false,
            'is_department_head_record' => true,
        ]);
    }

    private function buildDepartmentAdminEmployeePaginator(
        ?string $departmentName,
        ?string $searchTerm,
        int $perPage,
        int $page,
        array $departmentHeadLookup = []
    ): LengthAwarePaginator {
        $employees = Employee::query()
            ->when($departmentName, function ($query) use ($departmentName) {
                $query->where('office', $departmentName);
            })
            ->when($searchTerm, function ($query, $term) {
                $query->where(function ($q) use ($term) {
                    $q->where('firstname', 'LIKE', "%{$term}%")
                        ->orWhere('surname', 'LIKE', "%{$term}%");
                });
            })
            ->orderBy('surname')
            ->orderBy('firstname')
            ->paginate($perPage, ['*'], 'page', $page);

        $employees->getCollection()->transform(function (Employee $emp) use ($departmentHeadLookup) {
            $controlNo = trim((string) $emp->control_no);
            return array_merge($this->serializeEmployee($emp), [
                'has_account' => false,
                'is_department_head_record' => $controlNo !== '' && isset($departmentHeadLookup[$controlNo]),
            ]);
        });

        return $employees;
    }

    private function buildDepartmentAdminStatusCounts(?string $departmentName, ?string $searchTerm): array
    {
        $statusCountsQuery = Employee::query();
        if ($departmentName) {
            $statusCountsQuery->where('office', $departmentName);
        }
        if ($searchTerm) {
            $statusCountsQuery->where(function ($q) use ($searchTerm) {
                $q->where('firstname', 'LIKE', "%{$searchTerm}%")
                    ->orWhere('surname', 'LIKE', "%{$searchTerm}%");
            });
        }

        return $statusCountsQuery
            ->selectRaw('status, COUNT(*) as count')
            ->groupBy('status')
            ->pluck('count', 'status')
            ->toArray();
    }

    private function buildHrEmployeeListing(
        Request $request,
        ?string $departmentName,
        ?int $departmentId,
        ?string $searchTerm,
        int $perPage,
        int $page,
        array $departmentHeadLookup = []
    ): array {
        $employeeRows = Employee::query()
            ->when($departmentName, function ($query) use ($departmentName) {
                $query->where('office', $departmentName);
            })
            ->where(function ($q) {
                $q->whereNull('status')
                    ->orWhereRaw("UPPER(LTRIM(RTRIM(status))) <> 'CONTRACTUAL'");
            })
            ->when($searchTerm, function ($query, $term) {
                $query->where(function ($q) use ($term) {
                    $q->where('firstname', 'LIKE', "%{$term}%")
                        ->orWhere('surname', 'LIKE', "%{$term}%");
                });
            })
            ->orderBy('surname')
            ->orderBy('firstname')
            ->get()
            ->map(function (Employee $employee) use ($departmentHeadLookup): array {
                $controlNo = trim((string) $employee->control_no);
                return array_merge($this->serializeEmployee($employee), [
                    'has_account' => false,
                    'is_department_head_record' => $controlNo !== '' && isset($departmentHeadLookup[$controlNo]),
                ]);
            });

        $existingEmployeeControlNos = $employeeRows
            ->map(fn(array $employee): string => trim((string) ($employee['control_no'] ?? '')))
            ->filter()
            ->values()
            ->all();
        $existingEmployeeControlNoLookup = array_fill_keys($existingEmployeeControlNos, true);

        $departmentHeadRows = DepartmentHead::query()
            ->when($departmentId, function ($query) use ($departmentId) {
                $query->where('department_id', $departmentId);
            }, function ($query) use ($departmentName) {
                if ($departmentName) {
                    $query->where('office', $departmentName);
                }
            })
            ->orderBy('surname')
            ->orderBy('firstname')
            ->get()
            ->filter(function (DepartmentHead $departmentHead) use (
                $departmentName,
                $searchTerm,
                $existingEmployeeControlNoLookup
            ): bool {
                if (!$this->matchesDepartmentHeadEmployeeFilters($departmentHead, $searchTerm, true)) {
                    return false;
                }

                $controlNo = trim((string) ($departmentHead->control_no ?? ''));
                if ($controlNo !== '' && isset($existingEmployeeControlNoLookup[$controlNo])) {
                    return false;
                }

                return true;
            })
            ->map(fn(DepartmentHead $departmentHead): array => $this->serializeDepartmentHeadAsEmployee($departmentHead));

        $combinedRows = $employeeRows
            ->concat($departmentHeadRows)
            ->sort(function (array $left, array $right): int {
                $leftSurname = mb_strtoupper(trim((string) ($left['surname'] ?? '')));
                $rightSurname = mb_strtoupper(trim((string) ($right['surname'] ?? '')));
                if ($leftSurname !== $rightSurname) {
                    return $leftSurname <=> $rightSurname;
                }

                $leftFirstname = mb_strtoupper(trim((string) ($left['firstname'] ?? '')));
                $rightFirstname = mb_strtoupper(trim((string) ($right['firstname'] ?? '')));
                if ($leftFirstname !== $rightFirstname) {
                    return $leftFirstname <=> $rightFirstname;
                }

                return strcmp(
                    trim((string) ($left['control_no'] ?? '')),
                    trim((string) ($right['control_no'] ?? ''))
                );
            })
            ->values();

        $paginatedRows = new LengthAwarePaginator(
            $combinedRows->forPage($page, $perPage)->values(),
            $combinedRows->count(),
            $perPage,
            $page,
            [
                'path' => $request->url(),
                'pageName' => 'page',
            ]
        );

        return [
            $paginatedRows,
            $combinedRows->count(),
            $this->buildStatusCountsFromRows($combinedRows),
        ];
    }

    private function buildStatusCountsFromRows(Collection $rows): array
    {
        $counts = [];

        foreach ($rows as $row) {
            $statusKey = strtoupper(trim((string) ($row['status'] ?? '')));
            if ($statusKey === '') {
                continue;
            }

            $counts[$statusKey] = ((int) ($counts[$statusKey] ?? 0)) + 1;
        }

        ksort($counts);

        return $counts;
    }

    private function buildDepartmentHeadLookup(?int $departmentId = null, ?string $departmentName = null): array
    {
        return DepartmentHead::query()
            ->when($departmentId !== null, function ($query) use ($departmentId) {
                $query->where('department_id', $departmentId);
            })
            ->when($departmentId === null && $departmentName, function ($query) use ($departmentName) {
                $query->where('office', $departmentName);
            })
            ->get()
            ->mapWithKeys(function (DepartmentHead $departmentHead): array {
                $controlNo = trim((string) $departmentHead->control_no);
                return $controlNo !== '' ? [$controlNo => $departmentHead] : [];
            })
            ->all();
    }

    private function syncDepartmentHeadToEmployeeRecord(DepartmentHead $departmentHead): void
    {
        $controlNo = trim((string) ($departmentHead->control_no ?? ''));
        if ($controlNo === '') {
            return;
        }

        Employee::query()->updateOrCreate(
            ['control_no' => $controlNo],
            [
                'surname' => trim((string) ($departmentHead->surname ?? '')),
                'firstname' => trim((string) ($departmentHead->firstname ?? '')),
                'middlename' => $this->trimNullable($departmentHead->middlename),
                'office' => trim((string) ($departmentHead->office ?? '')),
                'status' => strtoupper(trim((string) ($departmentHead->status ?? ''))),
                'designation' => $this->trimNullable($departmentHead->designation),
                'rate_mon' => $departmentHead->rate_mon !== null ? round((float) $departmentHead->rate_mon, 2) : null,
            ]
        );
    }

    private function shouldIncludeDepartmentHeadInEmployeeSummary(
        DepartmentHead $departmentHead,
        ?string $departmentName,
        ?string $searchTerm,
        bool $isHrAccount
    ): bool {
        if (!$this->matchesDepartmentHeadEmployeeFilters($departmentHead, $searchTerm, $isHrAccount)) {
            return false;
        }

        $controlNo = trim((string) ($departmentHead->control_no ?? ''));
        if ($controlNo === '') {
            return true;
        }

        return !Employee::query()
            ->when($departmentName, function ($query) use ($departmentName) {
                $query->where('office', $departmentName);
            })
            ->when($isHrAccount, function ($query) {
                $query->where(function ($q) {
                    $q->whereNull('status')
                        ->orWhereRaw("UPPER(LTRIM(RTRIM(status))) <> 'CONTRACTUAL'");
                });
            })
            ->when($searchTerm, function ($query, $term) {
                $query->where(function ($q) use ($term) {
                    $q->where('firstname', 'LIKE', "%{$term}%")
                        ->orWhere('surname', 'LIKE', "%{$term}%");
                });
            })
            ->where('control_no', $controlNo)
            ->exists();
    }

    private function matchesDepartmentHeadEmployeeFilters(
        DepartmentHead $departmentHead,
        ?string $searchTerm,
        bool $isHrAccount
    ): bool {
        $status = strtoupper(trim((string) ($departmentHead->status ?? '')));
        if ($isHrAccount && $status === 'CONTRACTUAL') {
            return false;
        }

        $term = trim((string) ($searchTerm ?? ''));
        if ($term === '') {
            return true;
        }

        $firstname = trim((string) ($departmentHead->firstname ?? ''));
        $surname = trim((string) ($departmentHead->surname ?? ''));

        return str_contains(strtolower($firstname), strtolower($term))
            || str_contains(strtolower($surname), strtolower($term));
    }

    private function buildDepartmentHeadFullName(array $attributes): string
    {
        return trim(implode(' ', array_filter([
            $attributes['firstname'] ?? null,
            $attributes['middlename'] ?? null,
            $attributes['surname'] ?? null,
        ], static fn (mixed $value): bool => $value !== null && trim((string) $value) !== '')));
    }

    private function trimNullable(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $text = trim((string) $value);
        return $text === '' ? null : $text;
    }
}
