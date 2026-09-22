<?php

namespace App\Http\Controllers\Attendance;

use App\Http\Controllers\Controller;
use App\Models\BiometricDevice;
use App\Models\BiometricEnrollment;
use App\Models\DailyTimeRecord;
use App\Models\Department;
use App\Models\DepartmentAdmin;
use App\Models\EmployeeDepartmentAssignment;
use App\Models\HRAccount;
use App\Models\HrisEmployee;
use App\Services\Attendance\DtrCalculationService;
use App\Services\Attendance\UsbAttlogParserService;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Controller for managing Daily Time Records (DTR / CSC Form 48),
 * manual adjustments (Pass Slips, Travel Orders), and device monitoring.
 */
class DailyTimeRecordController extends Controller
{
    public function __construct(
        private readonly DtrCalculationService $dtrService,
        private readonly UsbAttlogParserService $usbParser
    ) {}

    /**
     * Get monthly DTR records for a specific employee formatted for CSC Form 48.
     */
    public function employeeMonthlyDtr(Request $request, string $controlNo): JsonResponse
    {
        $validated = $request->validate([
            'year' => ['nullable', 'integer', 'min:2020', 'max:2099'],
            'month' => ['nullable', 'integer', 'min:1', 'max:12'],
        ]);

        $year = (int) ($validated['year'] ?? now()->year);
        $month = (int) ($validated['month'] ?? now()->month);

        $startDate = CarbonImmutable::createFromDate($year, $month, 1)->startOfMonth();
        $endDate = $startDate->endOfMonth();

        $employee = HrisEmployee::findByControlNo($controlNo, true)
            ?? HrisEmployee::findByControlNo($controlNo, false);
        if (! $employee) {
            return response()->json([
                'message' => 'Employee not found in active HRIS records.',
            ], 404);
        }

        $canonicalControlNo = (string) ($employee->control_no ?? $controlNo);

        // Calculate/refresh DTR for the full month
        $records = $this->dtrService->calculateForEmployeeRange(
            $canonicalControlNo,
            $startDate->format('Y-m-d'),
            $endDate->format('Y-m-d')
        );

        $daysLate = $records->filter(fn ($r) => (int) ($r->late_minutes ?? 0) > 0)->count();
        $daysOvertime = $records->filter(fn ($r) => (int) ($r->overtime_minutes ?? 0) > 0 || ! empty($r->ot_arrival))->count();

        $summary = [
            'present_days' => $records->where('status', DailyTimeRecord::STATUS_PRESENT)->count(),
            'absent_days' => $records->where('status', DailyTimeRecord::STATUS_ABSENT)->count(),
            'late_days' => $daysLate,
            'overtime_days' => $daysOvertime,
            'total_worked_hours' => round((float) $records->sum('rendered_hours'), 1),
            'total_late_minutes' => (int) $records->sum('late_minutes'),
            'total_undertime_minutes' => (int) $records->sum('undertime_minutes'),
            'total_rendered_hours' => (float) $records->sum('rendered_hours'),
            'days_present' => $records->where('status', DailyTimeRecord::STATUS_PRESENT)->count(),
            'days_on_leave' => $records->where('status', DailyTimeRecord::STATUS_ON_LEAVE)->count(),
            'days_absent' => $records->where('status', DailyTimeRecord::STATUS_ABSENT)->count(),
        ];

        return response()->json([
            'employee' => [
                'control_no' => $canonicalControlNo,
                'name' => trim(($employee->firstname ?? '').' '.($employee->middlename ? $employee->middlename[0].'. ' : '').($employee->surname ?? '')),
                'office' => $employee->office ?? 'General Office',
                'designation' => $employee->designation ?? 'Employee',
            ],
            'period' => [
                'year' => $year,
                'month' => $month,
                'month_name' => $startDate->format('F'),
                'start_date' => $startDate->format('Y-m-d'),
                'end_date' => $endDate->format('Y-m-d'),
                'days_in_month' => $startDate->daysInMonth,
            ],
            'records' => $records,
            'summary' => $summary,
        ]);
    }

    /**
     * Get department attendance overview for a specific date or cutoff.
     */
    public function departmentOverview(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'department_id' => ['nullable', 'integer'],
            'date' => ['nullable', 'date_format:Y-m-d'],
            'search' => ['nullable', 'string', 'max:100'],
        ]);

        $account = $request->user();
        $isHr = $account instanceof HRAccount;
        $isAdmin = $account instanceof DepartmentAdmin;

        if (! $isHr && ! $isAdmin) {
            return response()->json(['message' => 'Unauthorized access.'], 403);
        }

        $targetDepartmentId = $isAdmin ? (int) $account->department_id : (isset($validated['department_id']) ? (int) $validated['department_id'] : null);
        $targetDate = $validated['date'] ?? now()->format('Y-m-d');

        // Resolve assigned employee control numbers
        $query = EmployeeDepartmentAssignment::query();
        if ($targetDepartmentId !== null) {
            $query->where('department_id', $targetDepartmentId);
        }
        $assignedControlNos = $query->pluck('employee_control_no')->filter()->values()->all();

        if ($assignedControlNos === []) {
            return response()->json([
                'date' => $targetDate,
                'department_id' => $targetDepartmentId,
                'employees' => [],
                'stats' => ['present' => 0, 'on_leave' => 0, 'absent' => 0, 'total' => 0],
            ]);
        }

        // Compute DTR for today for these employees
        $dtrRows = [];
        $presentCount = 0;
        $lateCount = 0;
        $onLeaveCount = 0;
        $absentCount = 0;

        // Build all variants for assigned control numbers for robust biometric enrollment lookup
        $allLookupVariants = [];
        foreach ($assignedControlNos as $cNo) {
            $cNoStr = trim((string) $cNo);
            if ($cNoStr === '') {
                continue;
            }
            $allLookupVariants[] = $cNoStr;
            $allLookupVariants[] = ltrim($cNoStr, '0');
            $allLookupVariants[] = str_pad(ltrim($cNoStr, '0'), 6, '0', STR_PAD_LEFT);
        }
        $allLookupVariants = array_values(array_unique(array_filter($allLookupVariants)));

        $enrollments = BiometricEnrollment::query()
            ->whereIn('employee_control_no', $allLookupVariants)
            ->get();

        $enrollmentsMap = [];
        foreach ($enrollments as $enr) {
            $cNo = trim((string) $enr->employee_control_no);
            $unpadded = ltrim($cNo, '0');
            $padded = str_pad($unpadded, 6, '0', STR_PAD_LEFT);
            $enrollmentsMap[$cNo] = $enr;
            if ($unpadded !== '') {
                $enrollmentsMap[$unpadded] = $enr;
                $enrollmentsMap[$padded] = $enr;
            }
        }

        foreach ($assignedControlNos as $controlNo) {
            $controlNoStr = (string) $controlNo;
            $dtr = $this->dtrService->calculateForEmployeeDate($controlNoStr, $targetDate, $targetDepartmentId);
            $emp = HrisEmployee::findByControlNo($controlNoStr, true)
                ?? HrisEmployee::findByControlNo($controlNoStr, false);
            $canonicalControlNo = $emp?->control_no ?? $dtr->employee_control_no ?? $controlNoStr;
            $enrollment = $enrollmentsMap[$canonicalControlNo]
                ?? $enrollmentsMap[$controlNoStr]
                ?? $enrollmentsMap[ltrim($controlNoStr, '0')]
                ?? null;

            $fullName = $emp ? trim(($emp->firstname ?? '').' '.($emp->middlename ? $emp->middlename[0].'. ' : '').($emp->surname ?? '')) : 'Employee '.$canonicalControlNo;

            if ($dtr->status === DailyTimeRecord::STATUS_PRESENT) {
                $presentCount++;
                if ((int) ($dtr->late_minutes ?? 0) > 0) {
                    $lateCount++;
                }
            } elseif ($dtr->status === DailyTimeRecord::STATUS_ON_LEAVE || $dtr->status === DailyTimeRecord::STATUS_HALF_DAY) {
                $onLeaveCount++;
            } elseif ($dtr->status === DailyTimeRecord::STATUS_ABSENT) {
                $absentCount++;
            }

            $attendanceStatus = match ($dtr->status) {
                DailyTimeRecord::STATUS_PRESENT => ((int) ($dtr->late_minutes ?? 0) > 0 ? 'Late' : 'On Time'),
                DailyTimeRecord::STATUS_ON_LEAVE => 'On Leave',
                DailyTimeRecord::STATUS_HALF_DAY => 'Half Day',
                DailyTimeRecord::STATUS_REST_DAY => 'Rest Day',
                DailyTimeRecord::STATUS_ABSENT => 'Absent',
                default => $dtr->status ?: 'Absent',
            };

            $dtrRows[] = [
                'control_no' => $canonicalControlNo,
                'employee_name' => $fullName,
                'designation' => $emp->designation ?? 'Employee',
                'biometric_status' => $enrollment?->status ?? 'NOT_REGISTERED',
                'is_biometric_registered' => $enrollment?->isRegistered() ?? false,
                'synced_devices' => $enrollment?->synced_devices ?? [],
                'attendance_status' => $attendanceStatus,
                'dtr' => $dtr,
            ];
        }

        // Apply search filter if provided
        $searchTerm = trim((string) ($validated['search'] ?? ''));
        if ($searchTerm !== '') {
            $dtrRows = array_values(array_filter($dtrRows, function ($row) use ($searchTerm): bool {
                return stripos($row['employee_name'], $searchTerm) !== false
                    || stripos($row['control_no'], $searchTerm) !== false
                    || stripos((string) ($row['designation'] ?? ''), $searchTerm) !== false;
            }));
        }

        return response()->json([
            'date' => $targetDate,
            'department_id' => $targetDepartmentId,
            'employees' => $dtrRows,
            'stats' => [
                'total' => count($assignedControlNos),
                'present' => $presentCount,
                'late' => $lateCount,
                'on_leave' => $onLeaveCount,
                'absent' => $absentCount,
            ],
        ]);
    }

    /**
     * Manual adjustment for a DTR record (Pass Slip, Travel Order, Official Business).
     */
    public function adjust(Request $request, int $id): JsonResponse
    {
        $validated = $request->validate([
            'adjustment_reason' => [
                'nullable',
                'string',
                Rule::in([
                    'OFFICIAL_BUSINESS',
                    'TRAVEL_ORDER',
                    'PASS_SLIP',
                    'CERTIFICATE_OF_APPEARANCE',
                    'MANUAL_OVERRIDE',
                ]),
            ],
            'adjustment_remarks' => ['nullable', 'string', 'max:255'],
            'am_arrival' => ['nullable', 'string'],
            'am_departure' => ['nullable', 'string'],
            'pm_arrival' => ['nullable', 'string'],
            'pm_departure' => ['nullable', 'string'],
            'ot_arrival' => ['nullable', 'string'],
            'ot_departure' => ['nullable', 'string'],
        ]);

        $dtr = DailyTimeRecord::query()->find($id);
        if (! $dtr) {
            return response()->json(['message' => 'DTR record not found.'], 404);
        }

        $account = $request->user();
        $adminId = $account?->id ?? null;

        $dtr->is_adjusted = true;
        $dtr->adjustment_reason = $validated['adjustment_reason'] ?? 'MANUAL_OVERRIDE';
        $dtr->adjustment_remarks = $validated['adjustment_remarks'] ?? 'Manual time override';
        $dtr->adjusted_by_admin_id = $adminId;

        // Apply time overrides if supplied
        if (array_key_exists('am_arrival', $validated)) {
            $dtr->am_arrival = $this->normalizeTimeInput($validated['am_arrival']);
        }
        if (array_key_exists('am_departure', $validated)) {
            $dtr->am_departure = $this->normalizeTimeInput($validated['am_departure']);
        }
        if (array_key_exists('pm_arrival', $validated)) {
            $dtr->pm_arrival = $this->normalizeTimeInput($validated['pm_arrival']);
        }
        if (array_key_exists('pm_departure', $validated)) {
            $dtr->pm_departure = $this->normalizeTimeInput($validated['pm_departure']);
        }
        if (array_key_exists('ot_arrival', $validated)) {
            $dtr->ot_arrival = $this->normalizeTimeInput($validated['ot_arrival']);
        }
        if (array_key_exists('ot_departure', $validated)) {
            $dtr->ot_departure = $this->normalizeTimeInput($validated['ot_departure']);
        }

        // When justified with official business/pass slip, waive absence/tardiness
        $dtr->status = $dtr->adjustment_reason;
        if ($dtr->rendered_hours <= 0) {
            $dtr->rendered_hours = 8.00;
        }

        $dtr->save();

        return response()->json([
            'message' => 'DTR record adjusted successfully.',
            'dtr' => $dtr,
        ]);
    }

    /**
     * Override time punches for a DTR record (supporting Morning, Afternoon, and Overtime).
     */
    public function overrideTime(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'employee_control_no' => ['required', 'string'],
            'date' => ['required', 'date_format:Y-m-d'],
            'am_arrival' => ['nullable', 'string'],
            'am_departure' => ['nullable', 'string'],
            'pm_arrival' => ['nullable', 'string'],
            'pm_departure' => ['nullable', 'string'],
            'ot_arrival' => ['nullable', 'string'],
            'ot_departure' => ['nullable', 'string'],
        ]);

        $controlNo = trim((string) $validated['employee_control_no']);
        $dateStr = $validated['date'];

        $emp = HrisEmployee::findByControlNo($controlNo, true)
            ?? HrisEmployee::findByControlNo($controlNo, false);
        $canonicalControlNo = $emp?->control_no ?? $controlNo;

        // Ensure calculation service initializes or loads the record
        $dtr = $this->dtrService->calculateForEmployeeDate($canonicalControlNo, $dateStr);

        $amArrival = $this->normalizeTimeInput($validated['am_arrival'] ?? null);
        $amDeparture = $this->normalizeTimeInput($validated['am_departure'] ?? null);
        $pmArrival = $this->normalizeTimeInput($validated['pm_arrival'] ?? null);
        $pmDeparture = $this->normalizeTimeInput($validated['pm_departure'] ?? null);
        $otArrival = $this->normalizeTimeInput($validated['ot_arrival'] ?? null);
        $otDeparture = $this->normalizeTimeInput($validated['ot_departure'] ?? null);

        $account = $request->user();
        $adminId = $account?->id ?? null;

        $dtr->am_arrival = $amArrival;
        $dtr->am_departure = $amDeparture;
        $dtr->pm_arrival = $pmArrival;
        $dtr->pm_departure = $pmDeparture;
        $dtr->ot_arrival = $otArrival;
        $dtr->ot_departure = $otDeparture;

        $dtr->is_adjusted = true;
        $dtr->adjustment_reason = 'MANUAL_OVERRIDE';
        $dtr->adjustment_remarks = 'Manual time override by admin';
        $dtr->adjusted_by_admin_id = $adminId;

        // Calculate rendered regular hours
        $rendered = $this->dtrService->calculateRenderedHours(
            $amArrival,
            $amDeparture,
            $pmArrival,
            $pmDeparture
        );

        $hasAnyPunches = $amArrival !== null || $amDeparture !== null || $pmArrival !== null || $pmDeparture !== null;
        if ($hasAnyPunches) {
            $dtr->status = DailyTimeRecord::STATUS_PRESENT;
            $dtr->rendered_hours = $rendered > 0 ? $rendered : 8.00;
        }

        // Calculate overtime minutes if applicable
        if ($otArrival !== null && $otDeparture !== null) {
            try {
                $otIn = CarbonImmutable::parse($otArrival);
                $otOut = CarbonImmutable::parse($otDeparture);
                if ($otOut->isAfter($otIn)) {
                    $dtr->overtime_minutes = abs($otOut->diffInMinutes($otIn));
                }
            } catch (Throwable) {
                // ignore
            }
        }

        $dtr->save();

        return response()->json([
            'message' => 'Attendance time overridden successfully.',
            'dtr' => $dtr,
        ]);
    }

    private function normalizeTimeInput(?string $time): ?string
    {
        $trimmed = trim((string) ($time ?? ''));
        if ($trimmed === '' || $trimmed === '—' || $trimmed === '-') {
            return null;
        }

        try {
            return CarbonImmutable::parse($trimmed)->format('H:i:s');
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * Recalculate DTR for an employee or department for a date range.
     */
    public function recalculate(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'employee_control_no' => ['required', 'string'],
            'start_date' => ['required', 'date_format:Y-m-d'],
            'end_date' => ['required', 'date_format:Y-m-d'],
        ]);

        $records = $this->dtrService->calculateForEmployeeRange(
            (string) $validated['employee_control_no'],
            $validated['start_date'],
            $validated['end_date']
        );

        return response()->json([
            'message' => 'DTR recalculated successfully.',
            'count' => $records->count(),
        ]);
    }

    /**
     * List all registered ZKTeco MB360 biometric devices.
     */
    public function listDevices(): JsonResponse
    {
        $devices = BiometricDevice::query()
            ->orderBy('device_name')
            ->get();

        return response()->json([
            'devices' => $devices,
        ]);
    }

    /**
     * Authorize / Whitelist a new ZKTeco Biometric Terminal.
     * Accessible only to HR accounts.
     */
    public function storeDevice(Request $request): JsonResponse
    {
        if (! $request->user() instanceof HRAccount) {
            return response()->json([
                'message' => 'Unauthorized. Only HR administrators can authorize new biometric devices.',
            ], 403);
        }

        $validated = $request->validate([
            'serial_number' => ['required', 'string', 'max:100'],
            'device_name' => ['required', 'string', 'max:150'],
            'model_name' => ['nullable', 'string', 'max:100'],
            'department_id' => ['nullable', 'integer'],
            'department_name' => ['nullable', 'string', 'max:150'],
            'comm_key' => ['nullable', 'string', 'max:50'],
            'ip_address' => ['nullable', 'string', 'max:45'],
        ]);

        $serialNumber = trim($validated['serial_number']);

        $existing = BiometricDevice::query()->where('serial_number', $serialNumber)->first();
        if ($existing) {
            return response()->json([
                'message' => "Device with Serial Number '{$serialNumber}' is already registered.",
            ], 422);
        }

        $deptId = ! empty($validated['department_id']) ? (int) $validated['department_id'] : null;
        $deptName = trim((string) ($validated['department_name'] ?? ''));
        if ($deptId && empty($deptName)) {
            $dept = Department::query()->find($deptId);
            $deptName = $dept?->name ?? '';
        } elseif (! $deptId && $deptName !== '') {
            $dept = Department::query()->where('name', $deptName)->first();
            $deptId = $dept?->id;
        }

        $device = BiometricDevice::query()->create([
            'serial_number' => $serialNumber,
            'device_name' => trim($validated['device_name']),
            'model_name' => trim($validated['model_name'] ?? '') ?: 'MB360',
            'department_id' => $deptId,
            'department_name' => $deptName ?: 'Tagum City Hall',
            'comm_key' => trim($validated['comm_key'] ?? '') ?: '0',
            'ip_address' => trim($validated['ip_address'] ?? '') ?: null,
            'communication_mode' => 'ADMS',
            'is_active' => true,
            'status' => 'OFFLINE',
        ]);

        return response()->json([
            'message' => "Biometric device '{$device->device_name}' successfully authorized.",
            'device' => $device,
        ], 201);
    }

    /**
     * Toggle active authorization status for a device (Enable/Disable).
     * Accessible only to HR accounts.
     */
    public function toggleDeviceStatus(Request $request, int $id): JsonResponse
    {
        if (! $request->user() instanceof HRAccount) {
            return response()->json([
                'message' => 'Unauthorized. Only HR administrators can modify biometric device authorization.',
            ], 403);
        }

        $device = BiometricDevice::query()->findOrFail($id);
        $device->is_active = ! $device->is_active;
        if (! $device->is_active) {
            $device->status = 'OFFLINE';
        }
        $device->save();

        $action = $device->is_active ? 'enabled' : 'disabled';

        return response()->json([
            'message' => "Biometric device '{$device->device_name}' has been {$action}.",
            'device' => $device,
        ]);
    }

    /**
     * Update an authorized biometric device details.
     * Accessible only to HR accounts.
     */
    public function updateDevice(Request $request, int $id): JsonResponse
    {
        if (! $request->user() instanceof HRAccount) {
            return response()->json([
                'message' => 'Unauthorized. Only HR administrators can update biometric devices.',
            ], 403);
        }

        $device = BiometricDevice::query()->findOrFail($id);

        $validated = $request->validate([
            'device_name' => ['required', 'string', 'max:150'],
            'model_name' => ['nullable', 'string', 'max:100'],
            'department_id' => ['nullable', 'integer'],
            'department_name' => ['nullable', 'string', 'max:150'],
            'comm_key' => ['nullable', 'string', 'max:50'],
            'ip_address' => ['nullable', 'string', 'max:45'],
        ]);

        $deptId = ! empty($validated['department_id']) ? (int) $validated['department_id'] : null;
        $deptName = trim((string) ($validated['department_name'] ?? ''));
        if ($deptId && empty($deptName)) {
            $dept = Department::query()->find($deptId);
            $deptName = $dept?->name ?? '';
        } elseif (! $deptId && $deptName !== '') {
            $dept = Department::query()->where('name', $deptName)->first();
            $deptId = $dept?->id;
        }

        $device->update([
            'device_name' => trim($validated['device_name']),
            'model_name' => trim($validated['model_name'] ?? '') ?: ($device->model_name ?: 'MB360'),
            'department_id' => $deptId ?: $device->department_id,
            'department_name' => $deptName ?: ($device->department_name ?: 'Tagum City Hall'),
            'comm_key' => trim($validated['comm_key'] ?? '') ?: ($device->comm_key ?: '0'),
            'ip_address' => trim($validated['ip_address'] ?? '') ?: null,
        ]);

        return response()->json([
            'message' => "Biometric device '{$device->device_name}' updated successfully.",
            'device' => $device,
        ]);
    }

    /**
     * Delete an authorized biometric terminal.
     * Accessible only to HR accounts.
     */
    public function deleteDevice(Request $request, int $id): JsonResponse
    {
        if (! $request->user() instanceof HRAccount) {
            return response()->json([
                'message' => 'Unauthorized. Only HR administrators can delete biometric devices.',
            ], 403);
        }

        $device = BiometricDevice::query()->findOrFail($id);
        $name = $device->device_name;
        $device->delete();

        return response()->json([
            'message' => "Biometric terminal '{$name}' has been deleted.",
        ]);
    }

    /**
     * Import offline USB attendance logs (attlog.dat).
     */
    public function importUsb(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'file' => ['required', 'file', 'max:20480'], // max 20MB
            'device_serial_number' => ['nullable', 'string', 'max:50'],
        ]);

        $file = $request->file('file');
        $content = file_get_contents($file->getRealPath());

        if ($content === false) {
            return response()->json(['message' => 'Failed to read file content.'], 422);
        }

        $result = $this->usbParser->importFromContent($content, $validated['device_serial_number'] ?? null);

        return response()->json([
            'message' => 'USB attendance logs imported successfully.',
            'result' => $result,
        ]);
    }
}
