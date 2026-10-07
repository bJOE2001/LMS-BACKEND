<?php

namespace App\Http\Controllers\Attendance;

use App\Http\Controllers\Controller;
use App\Models\BiometricDeviceCommand;
use App\Models\BiometricEnrollment;
use App\Models\DepartmentAdmin;
use App\Models\EmployeeDepartmentAssignment;
use App\Models\HRAccount;
use App\Models\HrisEmployee;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class BiometricRegistrationController extends Controller
{
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
        $token = $this->getZkBioToken();
        if (! $token) {
            return 'Unable to authenticate with ZKBio Time. Check ZKBIO_API_USER / ZKBIO_API_PASS.';
        }

        $client = Http::timeout(15)->withHeaders(['Authorization' => 'Token '.$token]);

        try {
            $existing = $client->get($this->zkBioUrl('personnel/api/employees/'), ['emp_code' => $empCode])->json('data.0');

            if ($existing) {
                $employeeId = (int) $existing['id'];
                $areas = array_map('intval', array_column($existing['area'] ?? [], 'id'));

                if (! in_array($areaId, $areas, true)) {
                    $areas[] = $areaId;
                    $adjust = $client->post($this->zkBioUrl('personnel/api/employees/adjust_area/'), [
                        'employees' => [$employeeId],
                        'areas' => array_values(array_unique($areas)),
                    ]);
                    if (! $adjust->successful()) {
                        Log::error('ZKBio Time: adjust_area failed', ['emp_code' => $empCode, 'body' => $adjust->body()]);

                        return 'ZKBio Time rejected the area assignment.';
                    }
                }
            } else {
                $create = $client->post($this->zkBioUrl('personnel/api/employees/'), [
                    'emp_code' => $empCode,
                    'first_name' => $fullName,
                    'department' => 1,
                    'area' => [$areaId],
                ]);
                if (! $create->successful()) {
                    Log::error('ZKBio Time: employee create failed', ['emp_code' => $empCode, 'body' => $create->body()]);

                    return 'ZKBio Time rejected the employee record.';
                }
                $employeeId = (int) $create->json('id');
            }

            $resync = $client->post($this->zkBioUrl('personnel/api/employees/resync_to_device/'), [
                'employees' => [$employeeId],
            ]);
            if (! $resync->successful()) {
                Log::error('ZKBio Time: resync_to_device failed', ['emp_code' => $empCode, 'body' => $resync->body()]);

                return 'ZKBio Time could not queue the sync to the terminal.';
            }
        } catch (\Throwable $e) {
            Log::error('ZKBio Time: push failed', ['emp_code' => $empCode, 'error' => $e->getMessage()]);

            return 'Unable to reach ZKBio Time.';
        }

        return null;
    }

    /**
     * Resolve the ZKBio Time area for a terminal serial number.
     */
    private function findZkBioAreaId(string $deviceSn): ?int
    {
        $areaId = DB::connection('zkbio')->table('iclock_terminal')->where('sn', $deviceSn)->value('area_id');

        return $areaId !== null ? (int) $areaId : null;
    }

    public function hrIndex(Request $request): JsonResponse
    {
        $search = $request->input('search');
        $statusFilter = $request->input('status', 'all');

        $query = BiometricEnrollment::query();

        if ($search) {
            $query->where(function ($q) use ($search) {
                $q->where('employee_control_no', 'like', "%{$search}%")
                    ->orWhere('employee_name', 'like', "%{$search}%");
            });
        }

        if ($statusFilter !== 'all') {
            if ($statusFilter === 'not_registered') {
                $query->whereNull('id');
            } else {
                $query->where('status', strtoupper($statusFilter));
            }
        }

        $enrollments = $query->paginate(30);
        $enrolledControlNos = $enrollments->pluck('employee_control_no')->all();

        if ($statusFilter === 'all' || $statusFilter === 'not_registered') {
            $hrisQuery = HrisEmployee::query(true);
            if ($search) {
                $hrisQuery->where(function ($q) use ($search) {
                    $q->where('xp.ControlNo', 'like', "%{$search}%")
                        ->orWhere('xp.Firstname', 'like', "%{$search}%")
                        ->orWhere('xp.Surname', 'like', "%{$search}%");
                });
            }
            if ($enrolledControlNos !== []) {
                $hrisQuery->whereNotIn('xp.ControlNo', $enrolledControlNos);
            }
            $hrisEmployees = $hrisQuery->limit(30 - $enrollments->count())->get();
        } else {
            $hrisEmployees = collect();
        }

        $items = collect($enrollments->items())->map(function ($enrollment) {
            $hris = HrisEmployee::findByControlNo($enrollment->employee_control_no, true);

            return [
                'control_no' => $enrollment->employee_control_no,
                'full_name' => $enrollment->employee_name,
                'biometric_status' => $enrollment->status,
                'enrolled_at' => $enrollment->enrolled_at,
                'office' => $hris?->office ?: 'Unknown',
                'office_acronym' => $hris?->officeAcronym,
                'designation' => $hris?->designation,
            ];
        });

        foreach ($hrisEmployees as $hris) {
            $items->push([
                'control_no' => $hris->control_no,
                'full_name' => trim(($hris->firstname ?? '').' '.($hris->middlename ? $hris->middlename[0].'. ' : '').($hris->surname ?? '')),
                'biometric_status' => 'NOT REGISTERED',
                'enrolled_at' => null,
                'office' => $hris->office ?: 'Unknown',
                'office_acronym' => $hris->officeAcronym ?? null,
                'designation' => $hris->designation ?? null,
            ]);
        }

        $totalHris = HrisEmployee::query(true)->count();
        $allEnrollments = BiometricEnrollment::query()->get();
        $registeredCount = $allEnrollments->where('status', BiometricEnrollment::STATUS_REGISTERED)->count();
        $pendingCount = $allEnrollments->where('status', BiometricEnrollment::STATUS_PENDING_ENROLLMENT)->count();

        return response()->json([
            'employees' => $items,
            'total' => $enrollments->total() + ($statusFilter === 'not_registered' ? $hrisEmployees->count() : 0),
            'stats' => [
                'total_employees' => $totalHris,
                'registered' => $registeredCount,
                'pending' => $pendingCount,
                'not_registered' => max(0, $totalHris - $registeredCount - $pendingCount),
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
            'message' => "Registration initiated for {$fullName}. Please step up to the device to scan your fingerprint.",
            'command_id' => $command->id,
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
            'message' => "Employee {$fullName} assigned to Office Biometric Terminal ({$deviceSn}) via ZKBio Time API.",
            'command_id' => $command->id,
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
}
