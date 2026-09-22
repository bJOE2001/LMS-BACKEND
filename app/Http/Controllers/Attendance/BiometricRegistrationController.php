<?php

namespace App\Http\Controllers\Attendance;

use App\Http\Controllers\Controller;
use App\Models\BiometricDeviceCommand;
use App\Models\BiometricEnrollment;
use App\Models\DepartmentAdmin;
use App\Models\EmployeeDepartmentAssignment;
use App\Models\HrisEmployee;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Controller managing employee biometric enrollment by HR
 * and department terminal synchronization by Department Admins.
 */
class BiometricRegistrationController extends Controller
{
    /**
     * List all HRIS employees with their biometric enrollment status.
     */
    public function hrIndex(Request $request): JsonResponse
    {
        $search = trim((string) $request->query('search', ''));
        $statusFilter = trim((string) $request->query('status', 'all')); // all, REGISTERED, PENDING_ENROLLMENT, NOT_REGISTERED

        // Fetch all active HRIS employees from cache
        $allHris = HrisEmployee::allCached(true);

        // Fetch all biometric enrollments in BIO_DB
        $enrollments = BiometricEnrollment::query()->get();
        $enrollmentsMap = [];
        foreach ($enrollments as $enr) {
            $cNo = (string) $enr->employee_control_no;
            $enrollmentsMap[$cNo] = $enr;
            $enrollmentsMap[ltrim($cNo, '0')] = $enr;
        }

        $rows = [];
        $registeredCount = 0;
        $pendingCount = 0;
        $notRegisteredCount = 0;

        $searchLower = $search !== '' ? mb_strtolower($search) : '';

        foreach ($allHris as $emp) {
            $controlNo = (string) ($emp->control_no ?? '');
            $fullName = trim(($emp->firstname ?? '').' '.($emp->middlename ? $emp->middlename[0].'. ' : '').($emp->surname ?? ''));
            $enrollment = $enrollmentsMap[$controlNo] ?? $enrollmentsMap[ltrim($controlNo, '0')] ?? null;

            $status = $enrollment?->status ?? BiometricEnrollment::STATUS_NOT_REGISTERED;

            if ($status === BiometricEnrollment::STATUS_REGISTERED) {
                $registeredCount++;
            } elseif ($status === BiometricEnrollment::STATUS_PENDING_ENROLLMENT) {
                $pendingCount++;
            } else {
                $notRegisteredCount++;
            }

            if ($statusFilter !== 'all' && $status !== $statusFilter) {
                continue;
            }

            if ($searchLower !== '') {
                $matchesSearch = str_contains(mb_strtolower($controlNo), $searchLower)
                    || str_contains(mb_strtolower($fullName), $searchLower)
                    || str_contains(mb_strtolower((string) ($emp->office ?? '')), $searchLower)
                    || str_contains(mb_strtolower((string) ($emp->officeAcronym ?? '')), $searchLower)
                    || str_contains(mb_strtolower((string) ($emp->hris_office ?? '')), $searchLower)
                    || str_contains(mb_strtolower((string) ($emp->hrisOfficeAcronym ?? '')), $searchLower)
                    || str_contains(mb_strtolower((string) ($emp->assigned_department_name ?? '')), $searchLower)
                    || str_contains(mb_strtolower((string) ($emp->assignedDepartmentAcronym ?? '')), $searchLower)
                    || str_contains(mb_strtolower((string) ($emp->designation ?? '')), $searchLower);

                if (! $matchesSearch) {
                    continue;
                }
            }

            $rows[] = [
                'control_no' => $controlNo,
                'full_name' => $fullName,
                'office' => $emp->office ?? 'General Office',
                'officeAcronym' => $emp->officeAcronym ?? null,
                'office_acronym' => $emp->officeAcronym ?? $emp->office_acronym ?? null,
                'hris_office' => $emp->hris_office ?? ($emp->office ?? 'General Office'),
                'hrisOfficeAcronym' => $emp->hrisOfficeAcronym ?? null,
                'hris_office_acronym' => $emp->hrisOfficeAcronym ?? $emp->hris_office_acronym ?? null,
                'assigned_department_name' => $emp->assigned_department_name ?? null,
                'assignedDepartmentAcronym' => $emp->assignedDepartmentAcronym ?? $emp->assigned_department_acronym ?? null,
                'assigned_department_acronym' => $emp->assignedDepartmentAcronym ?? $emp->assigned_department_acronym ?? null,
                'assigned_department_id' => $emp->assigned_department_id !== null ? (int) $emp->assigned_department_id : null,
                'is_department_reassigned' => (bool) ($emp->is_department_reassigned ?? false),
                'designation' => $emp->designation ?? 'Employee',
                'biometric_status' => $status,
                'enrolled_at' => $enrollment?->enrolled_at?->format('Y-m-d H:i:s'),
                'enrollment_device_sn' => $enrollment?->enrollment_device_sn,
                'synced_devices' => $enrollment?->synced_devices ?? [],
            ];
        }

        // Return up to 500 rows if unfiltered to maintain smooth browser rendering
        $totalFound = count($rows);
        if ($search === '' && $statusFilter === 'all' && $totalFound > 500) {
            $rows = array_slice($rows, 0, 500);
        }

        return response()->json([
            'stats' => [
                'total_employees' => $allHris->count(),
                'registered' => $registeredCount,
                'pending' => $pendingCount,
                'not_registered' => $notRegisteredCount,
            ],
            'employees' => $rows,
            'total_count' => $totalFound,
        ]);
    }

    /**
     * HR queues an employee to the HR Enrollment Biometric Terminal.
     */
    public function hrRegister(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'employee_control_no' => ['required', 'string'],
            'device_serial_number' => ['required', 'string'],
        ]);

        $controlNo = $validated['employee_control_no'];
        $deviceSn = $validated['device_serial_number'];

        $emp = HrisEmployee::findByControlNo($controlNo, true);
        if (! $emp) {
            return response()->json(['message' => 'Employee not found in active HRIS records.'], 404);
        }

        $fullName = trim(($emp->firstname ?? '').' '.($emp->middlename ? $emp->middlename[0].'. ' : '').($emp->surname ?? ''));

        // 1. Create or update enrollment entry
        $enrollment = BiometricEnrollment::query()->firstOrNew([
            'employee_control_no' => $controlNo,
        ]);
        $enrollment->employee_name = $fullName;
        if (! $enrollment->isRegistered()) {
            $enrollment->status = BiometricEnrollment::STATUS_PENDING_ENROLLMENT;
        }
        $enrollment->enrollment_device_sn = $deviceSn;
        $enrollment->enrolled_by_user_id = $request->user()?->id;
        $enrollment->save();

        // 2. Queue ADMS command for the terminal
        $commandString = BiometricDeviceCommand::buildDataUserCommand($controlNo, $fullName);
        $command = BiometricDeviceCommand::query()->create([
            'device_serial_number' => $deviceSn,
            'command_type' => BiometricDeviceCommand::CMD_DATA_USER,
            'command_payload' => $commandString,
            'employee_control_no' => $controlNo,
            'employee_name' => $fullName,
            'status' => BiometricDeviceCommand::STATUS_PENDING,
        ]);

        return response()->json([
            'message' => "Employee {$fullName} successfully pushed to enrollment terminal ({$deviceSn}). Please guide employee to scan face/fingerprint.",
            'enrollment' => $enrollment,
            'command_id' => $command->id,
        ]);
    }

    /**
     * HR manually marks employee as registered/verified (e.g. for offline enrollment).
     */
    public function hrMarkEnrolled(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'employee_control_no' => ['required', 'string'],
            'notes' => ['nullable', 'string', 'max:255'],
        ]);

        $controlNo = $validated['employee_control_no'];
        $emp = HrisEmployee::findByControlNo($controlNo, true);
        if (! $emp) {
            return response()->json(['message' => 'Employee not found.'], 404);
        }

        $fullName = trim(($emp->firstname ?? '').' '.($emp->middlename ? $emp->middlename[0].'. ' : '').($emp->surname ?? ''));

        $enrollment = BiometricEnrollment::query()->firstOrNew([
            'employee_control_no' => $controlNo,
        ]);
        $enrollment->employee_name = $fullName;
        $enrollment->status = BiometricEnrollment::STATUS_REGISTERED;
        $enrollment->enrolled_at = now();
        $enrollment->enrolled_by_user_id = $request->user()?->id;
        if (isset($validated['notes'])) {
            $enrollment->notes = $validated['notes'];
        }
        $enrollment->save();

        return response()->json([
            'message' => "Employee {$fullName} successfully marked as biometrically registered.",
            'enrollment' => $enrollment,
        ]);
    }

    /**
     * Check biometric enrollment status for a specific employee.
     */
    public function employeeStatus(string $controlNo): JsonResponse
    {
        $enrollment = BiometricEnrollment::query()
            ->where('employee_control_no', $controlNo)
            ->first();

        return response()->json([
            'control_no' => $controlNo,
            'is_registered' => $enrollment?->isRegistered() ?? false,
            'status' => $enrollment?->status ?? 'NOT_REGISTERED',
            'enrolled_at' => $enrollment?->enrolled_at?->format('Y-m-d H:i:s'),
            'enrollment_device_sn' => $enrollment?->enrollment_device_sn,
            'synced_devices' => $enrollment?->synced_devices ?? [],
        ]);
    }

    /**
     * Office Admin pulls / pushes an HR-registered employee to the office's MB360 device.
     */
    public function adminPullToOffice(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'employee_control_no' => ['required', 'string'],
            'device_serial_number' => ['required', 'string'],
        ]);

        $controlNo = $validated['employee_control_no'];
        $deviceSn = $validated['device_serial_number'];

        // 1. Verify that employee has already been registered by HR
        $enrollment = BiometricEnrollment::query()
            ->where('employee_control_no', $controlNo)
            ->first();

        if (! $enrollment || ! $enrollment->isRegistered()) {
            return response()->json([
                'message' => 'Cannot sync to office device: Employee must first be registered on biometrics by HR.',
            ], 422);
        }

        $emp = HrisEmployee::findByControlNo($controlNo, true);
        $fullName = $emp
            ? trim(($emp->firstname ?? '').' '.($emp->middlename ? $emp->middlename[0].'. ' : '').($emp->surname ?? ''))
            : $enrollment->employee_name;

        // 2. Queue ADMS command for the office terminal
        $commandString = BiometricDeviceCommand::buildDataUserCommand($controlNo, $fullName);
        $command = BiometricDeviceCommand::query()->create([
            'device_serial_number' => $deviceSn,
            'command_type' => BiometricDeviceCommand::CMD_DATA_USER,
            'command_payload' => $commandString,
            'employee_control_no' => $controlNo,
            'employee_name' => $fullName,
            'status' => BiometricDeviceCommand::STATUS_PENDING,
        ]);

        return response()->json([
            'message' => "Employee {$fullName} queued to Office Biometric Terminal ({$deviceSn}). The device will download the profile on its next sync.",
            'command_id' => $command->id,
        ]);
    }

    /**
     * Office Admin batch-syncs all registered employees in the department roster to the office's MB360 device.
     */
    public function adminPullDepartmentRoster(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'device_serial_number' => ['required', 'string'],
        ]);

        $deviceSn = $validated['device_serial_number'];
        $account = $request->user();

        if (! ($account instanceof DepartmentAdmin)) {
            return response()->json(['message' => 'Unauthorized: Only Department Admins can sync office roster.'], 403);
        }

        $deptId = (int) $account->department_id;
        $assignedControlNos = EmployeeDepartmentAssignment::query()
            ->where('department_id', $deptId)
            ->pluck('employee_control_no')
            ->filter()
            ->values()
            ->all();

        if ($assignedControlNos === []) {
            return response()->json(['message' => 'No employees assigned to this department.'], 404);
        }

        // Filter only those registered by HR
        $registeredEnrollments = BiometricEnrollment::query()
            ->whereIn('employee_control_no', $assignedControlNos)
            ->where('status', BiometricEnrollment::STATUS_REGISTERED)
            ->get();

        $queuedCount = 0;
        foreach ($registeredEnrollments as $enrollment) {
            $commandString = BiometricDeviceCommand::buildDataUserCommand(
                $enrollment->employee_control_no,
                $enrollment->employee_name
            );

            BiometricDeviceCommand::query()->create([
                'device_serial_number' => $deviceSn,
                'command_type' => BiometricDeviceCommand::CMD_DATA_USER,
                'command_payload' => $commandString,
                'employee_control_no' => $enrollment->employee_control_no,
                'employee_name' => $enrollment->employee_name,
                'status' => BiometricDeviceCommand::STATUS_PENDING,
            ]);

            $queuedCount++;
        }

        return response()->json([
            'message' => "Successfully queued {$queuedCount} registered employee(s) to Office Terminal ({$deviceSn}).",
            'queued_count' => $queuedCount,
            'total_in_roster' => count($assignedControlNos),
            'skipped_unregistered' => count($assignedControlNos) - $queuedCount,
        ]);
    }

    /**
     * Scan existing attendance logs and automatically mark all punched employees as registered.
     */
    public function syncFromLogs(): JsonResponse
    {
        $rawLogs = \App\Models\AttendanceRawLog::query()
            ->select('biometric_pin', 'device_serial_number', 'punch_time')
            ->orderBy('id')
            ->get();

        $byPin = [];
        foreach ($rawLogs as $log) {
            $pin = trim((string) $log->biometric_pin);
            if ($pin === '') {
                continue;
            }
            if (! isset($byPin[$pin])) {
                $byPin[$pin] = [
                    'device_sn' => $log->device_serial_number,
                    'punch_time' => $log->punch_time,
                ];
            }
        }

        $syncedCount = 0;
        foreach ($byPin as $pin => $info) {
            $emp = HrisEmployee::findByControlNo($pin, true);
            $canonicalControlNo = $emp?->control_no ? (string) $emp->control_no : $pin;

            $enrollment = BiometricEnrollment::query()
                ->where('employee_control_no', $canonicalControlNo)
                ->orWhere('employee_control_no', $pin)
                ->orWhere('employee_control_no', ltrim($pin, '0'))
                ->first();

            if (! $enrollment) {
                $enrollment = new BiometricEnrollment([
                    'employee_control_no' => $canonicalControlNo,
                ]);
            }

            $needsSave = false;
            if (! $enrollment->isRegistered()) {
                $fullName = $emp
                    ? trim(($emp->firstname ?? '').' '.($emp->middlename ? $emp->middlename[0].'. ' : '').($emp->surname ?? ''))
                    : 'EMP '.$pin;

                $enrollment->employee_name = $fullName;
                $enrollment->status = BiometricEnrollment::STATUS_REGISTERED;
                $enrollment->enrolled_at = $enrollment->enrolled_at ?? ($info['punch_time'] ?? now());
                $enrollment->enrollment_device_sn = $enrollment->enrollment_device_sn ?: ($info['device_sn'] ?: null);
                $enrollment->notes = $enrollment->notes ?: 'Auto-registered from physical biometric device punch';
                $needsSave = true;
                $syncedCount++;
            }

            if (! empty($info['device_sn'])) {
                $synced = $enrollment->synced_devices ?? [];
                if (! in_array($info['device_sn'], $synced, true)) {
                    $synced[] = $info['device_sn'];
                    $enrollment->synced_devices = $synced;
                    $needsSave = true;
                }
            }

            if ($needsSave) {
                $enrollment->save();
            }
        }

        return response()->json([
            'message' => "Successfully synchronized {$syncedCount} employee(s) from attendance logs into biometric registry.",
            'synced_count' => $syncedCount,
            'total_punched_employees' => count($byPin),
        ]);
    }
}
