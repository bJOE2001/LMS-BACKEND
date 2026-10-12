<?php

namespace App\Http\Controllers\Attendance;

use App\Http\Controllers\Controller;
use App\Models\BiometricDevice;
use App\Models\BiometricEnrollment;
use App\Models\BiometricTransferLog;
use App\Models\DepartmentAdmin;
use App\Models\EmployeeDepartmentAssignment;
use App\Models\HRAccount;
use App\Models\HrisEmployee;
use App\Services\Attendance\ZkBioTimeReconciliationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class BiometricRegistrationController extends Controller
{
    public function __construct(
        protected ZkBioTimeReconciliationService $reconciliationService
    ) {}

    private function zkBioUrl(string $path): string
    {
        return rtrim((string) config('services.zkbio.base_url', 'http://127.0.0.1'), '/').'/'.ltrim($path, '/');
    }

    private function getZkBioToken(): ?string
    {
        try {
            return Http::timeout(10)->post($this->zkBioUrl('api-token-auth/'), [
                'username' => config('services.zkbio.api_user'),
                'password' => config('services.zkbio.api_pass'),
            ])->json('token');
        } catch (\Throwable $e) {
            Log::error('ZKBio Time: token request failed', ['error' => $e->getMessage()]);

            return null;
        }
    }

    /**
     * Ensure the employee exists in ZKBio Time, is assigned to the terminal's area,
     * and force ZKBio Time to (re)send the user record to every terminal in that area.
     *
     * @return string|null Error message, or null on success.
     */
    private function pushEmployeeToZkBioTime(string $empCode, string $fullName, int $areaId): ?string
    {
        $result = $this->reconciliationService->assignEmployeeExclusivelyToArea($empCode, $areaId, $fullName);

        if (! $result['success']) {
            return $result['message'];
        }

        return null;
    }

    /**
     * Resolve the ZKBio Time area for a terminal serial number.
     */
    private function findZkBioAreaId(string $deviceSn): ?int
    {
        $bioArea = BiometricDevice::query()->where('serial_number', $deviceSn)->value('zkbio_area_id');
        if ($bioArea !== null && (int) $bioArea > 1) {
            return (int) $bioArea;
        }

        $areaId = DB::connection('zkbio')->table('iclock_terminal')->where('sn', $deviceSn)->value('area_id');

        return ($areaId !== null && (int) $areaId > 1) ? (int) $areaId : null;
    }

    public function hrIndex(Request $request): JsonResponse
    {
        $search = trim((string) $request->input('search', ''));
        $statusFilter = strtolower(trim((string) $request->input('status', 'all')));
        $page = max(1, (int) $request->input('page', 1));
        $perPage = max(1, min(100, (int) $request->input('per_page', 30)));

        $allEnrollments = BiometricEnrollment::query()->get();
        $registeredCount = $allEnrollments->whereIn('status', [BiometricEnrollment::STATUS_REGISTERED, BiometricEnrollment::STATUS_FINGERPRINT_ENROLLED])->count();
        $pendingCount = $allEnrollments->where('status', BiometricEnrollment::STATUS_PENDING_ENROLLMENT)->count();
        $totalHris = HrisEmployee::query(true)->count();
        $notRegisteredCount = max(0, $totalHris - $registeredCount - $pendingCount);

        $enrolledControlNos = $allEnrollments
            ->filter(fn ($e) => in_array($e->status, [
                BiometricEnrollment::STATUS_REGISTERED,
                BiometricEnrollment::STATUS_FINGERPRINT_ENROLLED,
                BiometricEnrollment::STATUS_PENDING_ENROLLMENT,
            ], true))
            ->pluck('employee_control_no')
            ->all();

        $deviceMap = BiometricDevice::query()->get()->keyBy('serial_number');
        $resolveDeviceOffices = function ($enrollment) use ($deviceMap) {
            $sns = $enrollment->synced_devices ?: ($enrollment->enrollment_device_sn ? [$enrollment->enrollment_device_sn] : []);

            return collect($sns)->map(function ($sn) use ($deviceMap) {
                $dev = $deviceMap->get($sn);

                return $dev ? ($dev->device_name ?: $dev->department_name ?: $sn) : $sn;
            })->unique()->values()->all();
        };

        $items = collect();
        $total = 0;

        if ($statusFilter === 'registered') {
            $query = BiometricEnrollment::query()
                ->whereIn('status', [BiometricEnrollment::STATUS_REGISTERED, BiometricEnrollment::STATUS_FINGERPRINT_ENROLLED]);

            if ($search !== '') {
                $query->where(function ($q) use ($search) {
                    $q->where('employee_control_no', 'like', "%{$search}%")
                        ->orWhere('employee_name', 'like', "%{$search}%");
                });
            }

            $total = $query->count();
            $enrollments = $query->forPage($page, $perPage)->get();

            $items = $enrollments->map(function ($enrollment) use ($resolveDeviceOffices) {
                $hris = HrisEmployee::findByControlNo($enrollment->employee_control_no, true);

                return [
                    'control_no' => $enrollment->employee_control_no,
                    'full_name' => $enrollment->employee_name,
                    'biometric_status' => BiometricEnrollment::STATUS_REGISTERED,
                    'enrolled_at' => $enrollment->enrolled_at,
                    'enrollment_device_sn' => $enrollment->enrollment_device_sn,
                    'synced_devices' => $enrollment->synced_devices ?? [],
                    'device_offices' => $resolveDeviceOffices($enrollment),
                    'office' => $hris?->office ?: 'Unknown',
                    'office_acronym' => $hris?->officeAcronym,
                    'designation' => $hris?->designation,
                ];
            });
        } elseif ($statusFilter === 'pending' || $statusFilter === 'pending_enrollment') {
            $query = BiometricEnrollment::query()
                ->where('status', BiometricEnrollment::STATUS_PENDING_ENROLLMENT);

            if ($search !== '') {
                $query->where(function ($q) use ($search) {
                    $q->where('employee_control_no', 'like', "%{$search}%")
                        ->orWhere('employee_name', 'like', "%{$search}%");
                });
            }

            $total = $query->count();
            $enrollments = $query->forPage($page, $perPage)->get();

            foreach ($enrollments as $enrollment) {
                $this->reconciliationService->reconcileEnrollment($enrollment);
            }

            $items = $enrollments->map(function ($enrollment) use ($resolveDeviceOffices) {
                $hris = HrisEmployee::findByControlNo($enrollment->employee_control_no, true);

                return [
                    'control_no' => $enrollment->employee_control_no,
                    'full_name' => $enrollment->employee_name,
                    'biometric_status' => $enrollment->status,
                    'enrolled_at' => $enrollment->enrolled_at,
                    'enrollment_device_sn' => $enrollment->enrollment_device_sn,
                    'synced_devices' => $enrollment->synced_devices ?? [],
                    'device_offices' => $resolveDeviceOffices($enrollment),
                    'office' => $hris?->office ?: 'Unknown',
                    'office_acronym' => $hris?->officeAcronym,
                    'designation' => $hris?->designation,
                ];
            });
        } elseif ($statusFilter === 'not_registered') {
            $hrisQuery = HrisEmployee::query(true);

            if (! empty($enrolledControlNos)) {
                $hrisQuery->whereNotIn('xp.ControlNo', $enrolledControlNos);
            }

            if ($search !== '') {
                $hrisQuery->where(function ($q) use ($search) {
                    $q->where('xp.ControlNo', 'like', "%{$search}%")
                        ->orWhere('xp.Firstname', 'like', "%{$search}%")
                        ->orWhere('xp.Surname', 'like', "%{$search}%");
                });
            }

            $total = $hrisQuery->count();
            $hrisEmployees = $hrisQuery->forPage($page, $perPage)->get();

            $items = $hrisEmployees->map(function ($hris) {
                return [
                    'control_no' => $hris->control_no,
                    'full_name' => trim(($hris->firstname ?? '').' '.($hris->middlename ? $hris->middlename[0].'. ' : '').($hris->surname ?? '')),
                    'biometric_status' => 'NOT REGISTERED',
                    'enrolled_at' => null,
                    'enrollment_device_sn' => null,
                    'synced_devices' => [],
                    'device_offices' => [],
                    'office' => $hris->office ?: 'Unknown',
                    'office_acronym' => $hris->officeAcronym ?? null,
                    'designation' => $hris->designation ?? null,
                ];
            });
        } else {
            // 'all'
            $enrollmentsQuery = BiometricEnrollment::query();
            if ($search !== '') {
                $enrollmentsQuery->where(function ($q) use ($search) {
                    $q->where('employee_control_no', 'like', "%{$search}%")
                        ->orWhere('employee_name', 'like', "%{$search}%");
                });
            }
            $enrollmentsTotal = $enrollmentsQuery->count();

            $hrisQuery = HrisEmployee::query(true);
            if (! empty($enrolledControlNos)) {
                $hrisQuery->whereNotIn('xp.ControlNo', $enrolledControlNos);
            }
            if ($search !== '') {
                $hrisQuery->where(function ($q) use ($search) {
                    $q->where('xp.ControlNo', 'like', "%{$search}%")
                        ->orWhere('xp.Firstname', 'like', "%{$search}%")
                        ->orWhere('xp.Surname', 'like', "%{$search}%");
                });
            }
            $hrisTotal = $hrisQuery->count();
            $total = $enrollmentsTotal + $hrisTotal;

            $offset = ($page - 1) * $perPage;

            if ($offset < $enrollmentsTotal) {
                $takeEnrollments = min($perPage, $enrollmentsTotal - $offset);
                $enrollments = $enrollmentsQuery->skip($offset)->take($takeEnrollments)->get();

                foreach ($enrollments as $enrollment) {
                    $hris = HrisEmployee::findByControlNo($enrollment->employee_control_no, true);
                    $status = $enrollment->status;
                    if ($status === BiometricEnrollment::STATUS_FINGERPRINT_ENROLLED) {
                        $status = BiometricEnrollment::STATUS_REGISTERED;
                    }

                    $items->push([
                        'control_no' => $enrollment->employee_control_no,
                        'full_name' => $enrollment->employee_name,
                        'biometric_status' => $status,
                        'enrolled_at' => $enrollment->enrolled_at,
                        'enrollment_device_sn' => $enrollment->enrollment_device_sn,
                        'synced_devices' => $enrollment->synced_devices ?? [],
                        'device_offices' => $resolveDeviceOffices($enrollment),
                        'office' => $hris?->office ?: 'Unknown',
                        'office_acronym' => $hris?->officeAcronym,
                        'designation' => $hris?->designation,
                    ]);
                }

                $remaining = $perPage - $items->count();
                if ($remaining > 0) {
                    $hrisEmployees = $hrisQuery->skip(0)->take($remaining)->get();
                    foreach ($hrisEmployees as $hris) {
                        $items->push([
                            'control_no' => $hris->control_no,
                            'full_name' => trim(($hris->firstname ?? '').' '.($hris->middlename ? $hris->middlename[0].'. ' : '').($hris->surname ?? '')),
                            'biometric_status' => 'NOT REGISTERED',
                            'enrolled_at' => null,
                            'enrollment_device_sn' => null,
                            'synced_devices' => [],
                            'device_offices' => [],
                            'office' => $hris->office ?: 'Unknown',
                            'office_acronym' => $hris->officeAcronym ?? null,
                            'designation' => $hris->designation ?? null,
                        ]);
                    }
                }
            } else {
                $hrisOffset = $offset - $enrollmentsTotal;
                $hrisEmployees = $hrisQuery->skip($hrisOffset)->take($perPage)->get();

                foreach ($hrisEmployees as $hris) {
                    $items->push([
                        'control_no' => $hris->control_no,
                        'full_name' => trim(($hris->firstname ?? '').' '.($hris->middlename ? $hris->middlename[0].'. ' : '').($hris->surname ?? '')),
                        'biometric_status' => 'NOT REGISTERED',
                        'enrolled_at' => null,
                        'enrollment_device_sn' => null,
                        'synced_devices' => [],
                        'device_offices' => [],
                        'office' => $hris->office ?: 'Unknown',
                        'office_acronym' => $hris->officeAcronym ?? null,
                        'designation' => $hris->designation ?? null,
                    ]);
                }
            }
        }

        return response()->json([
            'employees' => $items->values()->all(),
            'total' => $total,
            'page' => $page,
            'per_page' => $perPage,
            'stats' => [
                'total_employees' => $totalHris,
                'registered' => $registeredCount,
                'pending' => $pendingCount,
                'not_registered' => $notRegisteredCount,
            ],
        ]);
    }

    public function hrRegister(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'employee_control_no' => ['required', 'string'],
            'device_serial_number' => ['required', 'string'],
        ]);

        if (! $request->user() instanceof HRAccount) {
            return response()->json(['message' => 'Unauthorized: Only HR can initiate biometric registration.'], 403);
        }

        $controlNo = $validated['employee_control_no'];
        $deviceSn = $validated['device_serial_number'];

        $emp = HrisEmployee::findByControlNo($controlNo, true);
        $fullName = $emp
            ? trim(($emp->firstname ?? '').' '.($emp->middlename ? $emp->middlename[0].'. ' : '').($emp->surname ?? ''))
            : 'EMP '.$controlNo;

        $enrollment = BiometricEnrollment::query()->firstOrCreate(
            ['employee_control_no' => $controlNo],
            ['employee_name' => $fullName]
        );

        $enrollment->update([
            'status' => BiometricEnrollment::STATUS_PENDING_ENROLLMENT,
            'enrollment_device_sn' => $deviceSn,
        ]);

        $areaId = $this->findZkBioAreaId($deviceSn);
        if ($areaId === null) {
            return response()->json(['message' => "Terminal {$deviceSn} is not registered in ZKBio Time."], 404);
        }

        $error = $this->pushEmployeeToZkBioTime($controlNo, $fullName, $areaId);
        if ($error !== null) {
            return response()->json(['message' => $error], 502);
        }

        $transferLog = BiometricTransferLog::query()->create([
            'employee_control_no' => $controlNo,
            'employee_name' => $fullName,
            'action_type' => BiometricTransferLog::ACTION_REGISTRATION,
            'target_department_id' => \App\Services\Attendance\BiometricTransferService::CHRMO_DEPARTMENT_ID,
            'target_office_name' => 'CHRMO',
            'target_device_sn' => $deviceSn,
            'target_area_id' => $areaId,
            'status' => BiometricTransferLog::STATUS_SUCCESS,
            'remarks' => "Registration initiated on device {$deviceSn}. Awaiting scan.",
            'performed_by_user_id' => $request->user()->id,
            'performed_by_name' => $request->user()->full_name ?? $request->user()->username ?? 'HR Admin',
        ]);

        return response()->json([
            'message' => "Registration initiated for {$fullName}. Please step up to the device to scan your fingerprint.",
            'transfer_log_id' => $transferLog->id,
            'command_id' => $transferLog->id,
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

        if ($enrollment && in_array($enrollment->status, [BiometricEnrollment::STATUS_PENDING_ENROLLMENT, BiometricEnrollment::STATUS_FINGERPRINT_ENROLLED], true)) {
            $this->reconciliationService->reconcileEnrollment($enrollment);
        }

        return response()->json([
            'control_no' => $controlNo,
            'is_registered' => $enrollment?->isRegistered() ?? false,
            'is_enrolled' => $enrollment?->isEnrolled() ?? false,
            'status' => $enrollment?->status ?? 'NOT_REGISTERED',
            'enrolled_at' => $enrollment?->enrolled_at?->format('Y-m-d H:i:s'),
            'enrollment_device_sn' => $enrollment?->enrollment_device_sn,
            'synced_devices' => $enrollment?->synced_devices ?? [],
        ]);
    }

    /**
     * Manually trigger biometric reconciliation against ZKBio Time and command logs.
     * Can reconcile a specific employee or all pending records.
     */
    public function reconcile(Request $request): JsonResponse
    {
        $controlNo = $request->input('employee_control_no');

        if ($controlNo) {
            $enrollment = BiometricEnrollment::query()->where('employee_control_no', $controlNo)->first();
            if (! $enrollment) {
                return response()->json(['message' => "No enrollment found for control number [{$controlNo}]."], 404);
            }

            $this->reconciliationService->reconcileEnrollment($enrollment);

            return response()->json([
                'message' => "Biometric enrollment for [{$controlNo}] reconciled.",
                'enrollment' => [
                    'control_no' => $enrollment->employee_control_no,
                    'status' => $enrollment->status,
                    'enrollment_device_sn' => $enrollment->enrollment_device_sn,
                    'enrolled_at' => $enrollment->enrolled_at?->format('Y-m-d H:i:s'),
                    'synced_devices' => $enrollment->synced_devices ?? [],
                    'notes' => $enrollment->notes,
                ],
            ]);
        }

        $updatedCount = $this->reconciliationService->reconcilePendingEnrollments();

        return response()->json([
            'message' => "Reconciliation complete. {$updatedCount} enrollment(s) updated.",
            'updated_count' => $updatedCount,
        ]);
    }

    public function adminPullToOffice(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'employee_control_no' => ['required', 'string'],
            'device_serial_number' => ['required', 'string'],
        ]);

        $account = $request->user();
        if (! ($account instanceof DepartmentAdmin)) {
            return response()->json(['message' => 'Unauthorized: Only Department Admins can pull employees.'], 403);
        }

        $controlNo = $validated['employee_control_no'];
        $deviceSn = $validated['device_serial_number'];

        $assignment = EmployeeDepartmentAssignment::query()
            ->where('employee_control_no', $controlNo)
            ->where('department_id', $account->department_id)
            ->first();

        if (! $assignment) {
            return response()->json(['message' => 'Employee is not assigned to your department roster.'], 403);
        }

        $enrollment = BiometricEnrollment::query()
            ->where('employee_control_no', $controlNo)
            ->where('status', BiometricEnrollment::STATUS_REGISTERED)
            ->first();

        $emp = HrisEmployee::findByControlNo($controlNo, true);
        $fullName = $emp
            ? trim(($emp->firstname ?? '').' '.($emp->middlename ? $emp->middlename[0].'. ' : '').($emp->surname ?? ''))
            : ($enrollment->employee_name ?? 'EMP '.$controlNo);

        $areaId = $this->findZkBioAreaId($deviceSn);
        if ($areaId === null) {
            return response()->json(['message' => "Terminal {$deviceSn} is not registered in ZKBio Time."], 404);
        }

        $error = $this->pushEmployeeToZkBioTime($controlNo, $fullName, $areaId);
        if ($error !== null) {
            return response()->json(['message' => $error], 502);
        }

        $deptLabel = $account->department?->acronym ?: ($account->department?->name ?: "Office #{$account->department_id}");
        $transferLog = BiometricTransferLog::query()->create([
            'employee_control_no' => $controlNo,
            'employee_name' => $fullName,
            'action_type' => BiometricTransferLog::ACTION_ASSIGN,
            'source_department_id' => \App\Services\Attendance\BiometricTransferService::CHRMO_DEPARTMENT_ID,
            'source_office_name' => 'CHRMO',
            'target_department_id' => $account->department_id,
            'target_office_name' => $deptLabel,
            'target_device_sn' => $deviceSn,
            'target_area_id' => $areaId,
            'status' => BiometricTransferLog::STATUS_SUCCESS,
            'remarks' => "Employee assigned to Office Biometric Terminal ({$deviceSn}) via ZKBio Time API.",
            'performed_by_user_id' => $account->id,
            'performed_by_name' => $account->full_name ?? $account->username ?? 'Department Admin',
        ]);

        return response()->json([
            'message' => "Employee {$fullName} assigned to Office Biometric Terminal ({$deviceSn}) via ZKBio Time API.",
            'transfer_log_id' => $transferLog->id,
            'command_id' => $transferLog->id,
        ]);
    }

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

        $registeredEnrollments = BiometricEnrollment::query()
            ->whereIn('employee_control_no', $assignedControlNos)
            ->get();

        $areaId = $this->findZkBioAreaId($deviceSn);
        if ($areaId === null) {
            return response()->json(['message' => 'Device not found in ZKBio Time.'], 404);
        }

        $queuedCount = 0;
        foreach ($registeredEnrollments as $enrollment) {
            $error = $this->pushEmployeeToZkBioTime($enrollment->employee_control_no, $enrollment->employee_name, $areaId);
            if ($error === null) {
                $queuedCount++;
            }
        }

        return response()->json([
            'message' => "Successfully synced {$queuedCount} employee(s) to Office Terminal ({$deviceSn}).",
            'queued_count' => $queuedCount,
            'total_in_roster' => count($assignedControlNos),
            'skipped_unregistered' => count($assignedControlNos) - $queuedCount,
        ]);
    }

    public function syncFromLogs(): JsonResponse
    {
        $rawLogs = \App\Models\AttendanceLog::query()
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
                ->firstOrCreate(['employee_control_no' => $canonicalControlNo]);

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
        ]);
    }

    /**
     * Retrieve paginated biometric transfer and assignment audit transaction logs.
     */
    public function transferLogs(Request $request): JsonResponse
    {
        $query = BiometricTransferLog::query();

        if ($request->filled('employee_control_no')) {
            $query->where('employee_control_no', $request->input('employee_control_no'));
        }

        if ($request->filled('action_type')) {
            $query->where('action_type', $request->input('action_type'));
        }

        if ($request->filled('status')) {
            $query->where('status', $request->input('status'));
        }

        if ($request->filled('search')) {
            $search = trim((string) $request->input('search'));
            $query->where(function ($q) use ($search): void {
                $q->where('employee_control_no', 'like', "%{$search}%")
                    ->orWhere('employee_name', 'like', "%{$search}%")
                    ->orWhere('source_office_name', 'like', "%{$search}%")
                    ->orWhere('target_office_name', 'like', "%{$search}%")
                    ->orWhere('remarks', 'like', "%{$search}%");
            });
        }

        $perPage = max(1, min(100, (int) $request->input('per_page', 20)));
        $logs = $query->orderByDesc('id')->paginate($perPage);

        return response()->json([
            'logs' => $logs->items(),
            'total' => $logs->total(),
            'current_page' => $logs->currentPage(),
            'last_page' => $logs->lastPage(),
        ]);
    }
}
