<?php

namespace App\Http\Controllers\Attendance;

use App\Http\Controllers\Controller;
use App\Models\BiometricDevice;
use App\Models\BiometricEnrollment;
use App\Models\DailyTimeRecord;
use App\Models\Department;
use App\Models\DepartmentAdmin;
use App\Models\DepartmentHead;
use App\Models\EmployeeDepartmentAssignment;
use App\Models\HRAccount;
use App\Models\HrisEmployee;
use App\Services\Attendance\DtrCalculationService;
use App\Services\Attendance\UsbAttlogParserService;
use App\Services\Attendance\ZkBioPunchSyncService;
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
        private readonly UsbAttlogParserService $usbParser,
        private readonly ZkBioPunchSyncService $punchSyncService
    ) {}

    /**
     * Get monthly DTR records for a specific employee formatted for CSC Form 48.
     */
    public function employeeMonthlyDtr(Request $request, string $controlNo): JsonResponse
    {
        $validated = $request->validate([
            'year' => ['nullable', 'integer', 'min:2020', 'max:2099'],
            'month' => ['nullable', 'integer', 'min:1', 'max:12'],
            'start_date' => ['nullable', 'date_format:Y-m-d'],
            'end_date' => ['nullable', 'date_format:Y-m-d'],
        ]);

        $year = (int) ($validated['year'] ?? now()->year);
        $month = (int) ($validated['month'] ?? now()->month);

        if (! empty($validated['start_date']) && ! empty($validated['end_date'])) {
            $startDate = CarbonImmutable::parse($validated['start_date'])->startOfDay();
            $endDate = CarbonImmutable::parse($validated['end_date'])->startOfDay();
            if ($startDate->isAfter($endDate)) {
                [$startDate, $endDate] = [$endDate, $startDate];
            }
            $year = $startDate->year;
            $month = $startDate->month;
        } else {
            $startDate = CarbonImmutable::createFromDate($year, $month, 1)->startOfMonth();
            $endDate = $startDate->endOfMonth();
        }

        $employee = HrisEmployee::findByControlNo($controlNo, true)
            ?? HrisEmployee::findByControlNo($controlNo, false);
        if (! $employee) {
            return response()->json([
                'message' => 'Employee not found in active HRIS records.',
            ], 404);
        }

        $canonicalControlNo = (string) ($employee->control_no ?? $controlNo);

        // Sync any recent punches from ZKBio Time
        $this->punchSyncService->syncPunches();

        // Calculate/refresh DTR for the requested period
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

        $devices = BiometricDevice::query()->get()->keyBy('serial_number')->map(fn ($d) => [
            'serial_number' => $d->serial_number,
            'device_name' => $d->device_name ?: 'Biometric Device',
            'location' => $d->location ?: 'Office',
        ]);

        $charges = HrisEmployee::chargesByControlNo(
            $canonicalControlNo,
            $startDate->format('Y-m-d'),
            $endDate->format('Y-m-d')
        );

        // Resolve default In-Charge signatory (Department Head)
        $defaultInCharge = [
            'name' => 'JOSEPH NELSON N. BRIONES',
            'title' => 'CICTMO HEAD',
            'control_no' => null,
        ];

        $assignment = EmployeeDepartmentAssignment::with('department.departmentHead')
            ->where('employee_control_no', $canonicalControlNo)
            ->first();

        $dept = $assignment?->department;
        if (! $dept && ! empty($employee->office)) {
            $dept = Department::with('departmentHead')
                ->where('name', $employee->office)
                ->first();
        }

        $head = $dept?->departmentHead;
        if ($head) {
            $middleInitial = $head->middlename ? ' '.strtoupper(trim($head->middlename)[0]).'.' : '';
            $headName = strtoupper(trim(($head->firstname ?? '').$middleInitial.' '.($head->surname ?? '')));
            if ($headName === '' && ! empty($head->full_name)) {
                $headName = strtoupper(trim($head->full_name));
            }

            $acronym = trim((string) ($dept->acronym ?? ''));
            $title = $acronym !== '' ? $acronym.' HEAD' : ($head->position ?: 'DEPARTMENT HEAD');

            $defaultInCharge = [
                'name' => $headName ?: 'DEPARTMENT HEAD',
                'title' => $title,
                'control_no' => $head->control_no,
            ];
        }

        // Active Department Heads list for modal dropdown
        $departmentHeads = DepartmentHead::query()
            ->with('department:id,name,acronym')
            ->orderBy('surname')
            ->get()
            ->map(function ($h) {
                $mi = $h->middlename ? ' '.strtoupper(trim($h->middlename)[0]).'.' : '';
                $name = strtoupper(trim(($h->firstname ?? '').$mi.' '.($h->surname ?? '')));
                if ($name === '' && ! empty($h->full_name)) {
                    $name = strtoupper(trim($h->full_name));
                }
                $acronym = trim((string) ($h->department?->acronym ?? ''));
                $title = $acronym !== '' ? $acronym.' HEAD' : ($h->position ?: 'DEPARTMENT HEAD');

                return [
                    'id' => $h->id,
                    'control_no' => $h->control_no,
                    'name' => $name,
                    'title' => $title,
                    'department' => $h->department?->name ?? $h->office ?? '',
                    'acronym' => $acronym,
                ];
            })
            ->values();

        return response()->json([
            'employee' => [
                'control_no' => $canonicalControlNo,
                'name' => trim(($employee->firstname ?? '').' '.($employee->middlename ? $employee->middlename[0].'. ' : '').($employee->surname ?? '')),
                'office' => $employee->office ?? 'General Office',
                'designation' => $employee->designation ?? 'Employee',
                'charges' => $charges ?? '',
                'employment_status' => strtoupper(trim((string) ($employee->status ?? ''))),
                'is_contractual' => strtoupper(trim((string) ($employee->status ?? ''))) === 'CONTRACTUAL',
                'in_charge' => $defaultInCharge,
            ],
            'in_charge' => $defaultInCharge,
            'department_heads' => $departmentHeads,
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
            'devices' => $devices,
        ]);
    }

    /**
     * Get monthly/cutoff DTR records for multiple employees formatted for bulk CSC Form 48 printing.
     */
    public function bulkDtr(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'control_nos' => ['required', 'array', 'min:1'],
            'control_nos.*' => ['required', 'string'],
            'year' => ['nullable', 'integer', 'min:2020', 'max:2099'],
            'month' => ['nullable', 'integer', 'min:1', 'max:12'],
            'start_date' => ['nullable', 'date_format:Y-m-d'],
            'end_date' => ['nullable', 'date_format:Y-m-d'],
        ]);

        $year = (int) ($validated['year'] ?? now()->year);
        $month = (int) ($validated['month'] ?? now()->month);

        if (! empty($validated['start_date']) && ! empty($validated['end_date'])) {
            $startDate = CarbonImmutable::parse($validated['start_date'])->startOfDay();
            $endDate = CarbonImmutable::parse($validated['end_date'])->startOfDay();
            if ($startDate->isAfter($endDate)) {
                [$startDate, $endDate] = [$endDate, $startDate];
            }
            $year = $startDate->year;
            $month = $startDate->month;
        } else {
            $startDate = CarbonImmutable::createFromDate($year, $month, 1)->startOfMonth();
            $endDate = $startDate->endOfMonth();
        }

        // Sync any recent punches from ZKBio Time
        $this->punchSyncService->syncPunches();

        $devices = BiometricDevice::query()->get()->keyBy('serial_number')->map(fn ($d) => [
            'serial_number' => $d->serial_number,
            'device_name' => $d->device_name ?: 'Biometric Device',
            'location' => $d->location ?: 'Office',
        ]);

        $departmentHeads = DepartmentHead::query()
            ->with('department:id,name,acronym')
            ->orderBy('surname')
            ->get()
            ->map(function ($h) {
                $mi = $h->middlename ? ' '.strtoupper(trim($h->middlename)[0]).'.' : '';
                $name = strtoupper(trim(($h->firstname ?? '').$mi.' '.($h->surname ?? '')));
                if ($name === '' && ! empty($h->full_name)) {
                    $name = strtoupper(trim($h->full_name));
                }
                $acronym = trim((string) ($h->department?->acronym ?? ''));
                $title = $acronym !== '' ? $acronym.' HEAD' : ($h->position ?: 'DEPARTMENT HEAD');

                return [
                    'id' => $h->id,
                    'control_no' => $h->control_no,
                    'name' => $name,
                    'title' => $title,
                    'department' => $h->department?->name ?? $h->office ?? '',
                    'acronym' => $acronym,
                ];
            })
            ->values();

        $period = [
            'year' => $year,
            'month' => $month,
            'month_name' => $startDate->format('F'),
            'start_date' => $startDate->format('Y-m-d'),
            'end_date' => $endDate->format('Y-m-d'),
            'days_in_month' => $startDate->daysInMonth,
        ];

        $results = [];

        foreach ($validated['control_nos'] as $controlNo) {
            $employee = HrisEmployee::findByControlNo($controlNo, true)
                ?? HrisEmployee::findByControlNo($controlNo, false);
            if (! $employee) {
                continue;
            }

            $canonicalControlNo = (string) ($employee->control_no ?? $controlNo);

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

            $charges = HrisEmployee::chargesByControlNo(
                $canonicalControlNo,
                $startDate->format('Y-m-d'),
                $endDate->format('Y-m-d')
            );

            // Default signatory
            $defaultInCharge = [
                'name' => 'JOSEPH NELSON N. BRIONES',
                'title' => 'CICTMO HEAD',
                'control_no' => null,
            ];

            $assignment = EmployeeDepartmentAssignment::with('department.departmentHead')
                ->where('employee_control_no', $canonicalControlNo)
                ->first();

            $dept = $assignment?->department;
            if (! $dept && ! empty($employee->office)) {
                $dept = Department::with('departmentHead')
                    ->where('name', $employee->office)
                    ->first();
            }

            $head = $dept?->departmentHead;
            if ($head) {
                $middleInitial = $head->middlename ? ' '.strtoupper(trim($head->middlename)[0]).'.' : '';
                $headName = strtoupper(trim(($head->firstname ?? '').$middleInitial.' '.($head->surname ?? '')));
                if ($headName === '' && ! empty($head->full_name)) {
                    $headName = strtoupper(trim($head->full_name));
                }

                $acronym = trim((string) ($dept->acronym ?? ''));
                $title = $acronym !== '' ? $acronym.' HEAD' : ($head->position ?: 'DEPARTMENT HEAD');

                $defaultInCharge = [
                    'name' => $headName ?: 'DEPARTMENT HEAD',
                    'title' => $title,
                    'control_no' => $head->control_no,
                ];
            }

            $results[] = [
                'employee' => [
                    'control_no' => $canonicalControlNo,
                    'name' => trim(($employee->firstname ?? '').' '.($employee->middlename ? $employee->middlename[0].'. ' : '').($employee->surname ?? '')),
                    'office' => $employee->office ?? 'General Office',
                    'designation' => $employee->designation ?? 'Employee',
                    'charges' => $charges ?? '',
                    'employment_status' => strtoupper(trim((string) ($employee->status ?? ''))),
                    'is_contractual' => strtoupper(trim((string) ($employee->status ?? ''))) === 'CONTRACTUAL',
                    'in_charge' => $defaultInCharge,
                ],
                'in_charge' => $defaultInCharge,
                'records' => $records,
                'summary' => $summary,
            ];
        }

        return response()->json([
            'period' => $period,
            'department_heads' => $departmentHeads,
            'devices' => $devices,
            'employees_dtr' => $results,
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

        // Sync any recent punches from ZKBio Time
        $this->punchSyncService->syncPunches();

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
            } elseif (
                $dtr->status === DailyTimeRecord::STATUS_ON_LEAVE ||
                $dtr->status === DailyTimeRecord::STATUS_HALF_DAY ||
                $dtr->status === DailyTimeRecord::STATUS_ON_LEAVE_AM ||
                $dtr->status === DailyTimeRecord::STATUS_ON_LEAVE_PM
            ) {
                $onLeaveCount++;
            } elseif ($dtr->status === DailyTimeRecord::STATUS_ABSENT) {
                $absentCount++;
            }

            $attendanceStatus = match ($dtr->status) {
                DailyTimeRecord::STATUS_PRESENT => ((int) ($dtr->late_minutes ?? 0) > 0 ? 'Late' : 'Present'),
                DailyTimeRecord::STATUS_ON_LEAVE => 'On Leave',
                DailyTimeRecord::STATUS_ON_LEAVE_AM, 'ON_LEAVE_AM' => 'On Leave (AM)',
                DailyTimeRecord::STATUS_ON_LEAVE_PM, 'ON_LEAVE_PM' => 'On Leave (PM)',
                DailyTimeRecord::STATUS_HALF_DAY => 'On Leave (Half Day)',
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

        $devices = BiometricDevice::query()->get()->keyBy('serial_number')->map(fn ($d) => [
            'serial_number' => $d->serial_number,
            'device_name' => $d->device_name ?: 'Biometric Device',
            'location' => $d->location ?: 'Office',
        ]);

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
            'devices' => $devices,
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

        $previousPunches = [
            'am_arrival' => $dtr->am_arrival,
            'am_departure' => $dtr->am_departure,
            'pm_arrival' => $dtr->pm_arrival,
            'pm_departure' => $dtr->pm_departure,
            'ot_arrival' => $dtr->ot_arrival,
            'ot_departure' => $dtr->ot_departure,
        ];

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

        $currentAdjusted = is_array($dtr->adjusted_slots) ? $dtr->adjusted_slots : [];
        if ($request->has('adjusted_slots') && is_array($request->input('adjusted_slots'))) {
            $submittedSlots = array_filter($request->input('adjusted_slots'));
            $mergedSlots = array_values(array_unique(array_merge($currentAdjusted, $submittedSlots)));
        } else {
            $detectedSlots = [];
            foreach ($previousPunches as $slotKey => $prevVal) {
                if (array_key_exists($slotKey, $validated) && $dtr->{$slotKey} !== $prevVal && $dtr->{$slotKey} !== null) {
                    $detectedSlots[] = $slotKey;
                }
            }
            $mergedSlots = array_values(array_unique(array_merge($currentAdjusted, $detectedSlots)));
        }
        $dtr->adjusted_slots = ! empty($mergedSlots) ? $mergedSlots : null;

        if ($dtr->adjustment_reason === 'MANUAL_OVERRIDE') {
            $schedule = $this->dtrService->resolveSchedule($dtr->employee_control_no);
            $lateAndUndertime = $this->dtrService->calculateLateAndUndertime(
                $dtr->am_arrival,
                $dtr->am_departure,
                $dtr->pm_arrival,
                $dtr->pm_departure,
                $schedule
            );
            $dtr->late_minutes = $lateAndUndertime['late_minutes'];
            $dtr->undertime_minutes = $lateAndUndertime['undertime_minutes'];
            $hasAnyPunches = $dtr->am_arrival !== null || $dtr->am_departure !== null || $dtr->pm_arrival !== null || $dtr->pm_departure !== null;
            $dtr->status = $hasAnyPunches ? DailyTimeRecord::STATUS_PRESENT : DailyTimeRecord::STATUS_ABSENT;
        } else {
            // When justified with official business/pass slip, waive absence/tardiness
            $dtr->late_minutes = 0;
            $dtr->undertime_minutes = 0;
            $dtr->status = $dtr->adjustment_reason;
            if ($dtr->rendered_hours <= 0) {
                $dtr->rendered_hours = 8.00;
            }
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
            'remark' => ['nullable', 'string', 'max:100'],
            'adjusted_slots' => ['nullable'],
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

        $previousPunches = [
            'am_arrival' => $dtr->am_arrival,
            'am_departure' => $dtr->am_departure,
            'pm_arrival' => $dtr->pm_arrival,
            'pm_departure' => $dtr->pm_departure,
            'ot_arrival' => $dtr->ot_arrival,
            'ot_departure' => $dtr->ot_departure,
        ];

        $dtr->am_arrival = $amArrival;
        $dtr->am_departure = $amDeparture;
        $dtr->pm_arrival = $pmArrival;
        $dtr->pm_departure = $pmDeparture;
        $dtr->ot_arrival = $otArrival;
        $dtr->ot_departure = $otDeparture;

        $rawAdjustedSlots = $request->input('adjusted_slots');
        if (is_array($rawAdjustedSlots)) {
            $isAssoc = array_keys($rawAdjustedSlots) !== range(0, count($rawAdjustedSlots) - 1);
            if ($isAssoc) {
                $filtered = array_filter($rawAdjustedSlots, fn ($v) => $v !== null && $v !== '');
                $dtr->adjusted_slots = ! empty($filtered) ? $filtered : null;
            } else {
                $submittedSlots = array_values(array_filter($rawAdjustedSlots));
                $currentAdjusted = is_array($dtr->adjusted_slots) ? array_values($dtr->adjusted_slots) : [];
                $mergedSlots = array_values(array_unique(array_merge($currentAdjusted, $submittedSlots)));
                $dtr->adjusted_slots = ! empty($mergedSlots) ? $mergedSlots : null;
            }
        } else {
            $newPunches = [
                'am_arrival' => $amArrival,
                'am_departure' => $amDeparture,
                'pm_arrival' => $pmArrival,
                'pm_departure' => $pmDeparture,
                'ot_arrival' => $otArrival,
                'ot_departure' => $otDeparture,
            ];
            $detectedSlots = [];
            foreach ($newPunches as $slotKey => $slotVal) {
                if ($slotVal !== ($previousPunches[$slotKey] ?? null) && $slotVal !== null) {
                    $detectedSlots[] = $slotKey;
                }
            }
            $currentAdjusted = is_array($dtr->adjusted_slots) ? array_values($dtr->adjusted_slots) : [];
            $mergedSlots = array_values(array_unique(array_merge($currentAdjusted, $detectedSlots)));
            $dtr->adjusted_slots = ! empty($mergedSlots) ? $mergedSlots : null;
        }

        $dtr->is_adjusted = true;
        $dtr->adjustment_reason = 'MANUAL_OVERRIDE';
        $dtr->adjustment_remarks = 'Manual time override by admin';
        $dtr->adjusted_by_admin_id = $adminId;

        $schedule = $this->dtrService->resolveSchedule($canonicalControlNo);

        // Calculate rendered regular hours
        $rendered = $this->dtrService->calculateRenderedHours(
            $amArrival,
            $amDeparture,
            $pmArrival,
            $pmDeparture,
            null,
            $schedule
        );

        $lateAndUndertime = $this->dtrService->calculateLateAndUndertime(
            $amArrival,
            $amDeparture,
            $pmArrival,
            $pmDeparture,
            $schedule
        );

        $dtr->late_minutes = $lateAndUndertime['late_minutes'];
        $dtr->undertime_minutes = $lateAndUndertime['undertime_minutes'];

        $slotMap = is_array($dtr->adjusted_slots) && (array_keys($dtr->adjusted_slots) !== range(0, count($dtr->adjusted_slots) - 1))
            ? $dtr->adjusted_slots
            : [];

        $amIsSpecial = in_array(strtoupper((string) ($slotMap['am_arrival'] ?? '')), ['O.F.', 'O.B.', 'OFFICIAL BUSINESS', 'ON FIELD'], true)
            || in_array(strtoupper((string) ($slotMap['am_departure'] ?? '')), ['O.F.', 'O.B.', 'OFFICIAL BUSINESS', 'ON FIELD'], true);

        $pmIsSpecial = in_array(strtoupper((string) ($slotMap['pm_arrival'] ?? '')), ['O.F.', 'O.B.', 'OFFICIAL BUSINESS', 'ON FIELD'], true)
            || in_array(strtoupper((string) ($slotMap['pm_departure'] ?? '')), ['O.F.', 'O.B.', 'OFFICIAL BUSINESS', 'ON FIELD'], true);

        if ($amIsSpecial) {
            $dtr->late_minutes = 0;
            $rendered += 4.00;
        }
        if ($pmIsSpecial) {
            $dtr->undertime_minutes = 0;
            $rendered += 4.00;
        }

        $hasAnyPunches = $amArrival !== null || $amDeparture !== null || $pmArrival !== null || $pmDeparture !== null || $amIsSpecial || $pmIsSpecial;
        $remarkInput = trim((string) ($validated['remark'] ?? ''));

        $hasSlotMap = ! empty($slotMap);

        if ($hasSlotMap) {
            if ($amIsSpecial && $pmIsSpecial) {
                $dtr->status = ($slotMap['am_arrival'] ?? '') === ($slotMap['pm_arrival'] ?? '') ? ($slotMap['am_arrival'] ?? DailyTimeRecord::STATUS_OFFICIAL_BUSINESS) : 'O.F. / O.B.';
                $dtr->adjustment_remarks = $dtr->status;
                $dtr->rendered_hours = 8.00;
            } elseif ($hasAnyPunches) {
                $dtr->status = DailyTimeRecord::STATUS_PRESENT;
                $dtr->adjustment_remarks = $remarkInput !== '' ? $remarkInput : 'Slot Override';
                $dtr->rendered_hours = min(8.00, (float) ($rendered > 0 ? $rendered : 8.00));
            } else {
                $dtr->status = DailyTimeRecord::STATUS_ABSENT;
                $dtr->rendered_hours = 0.00;
            }
        } elseif ($remarkInput !== '') {
            $upperRemark = strtoupper($remarkInput);
            $dtr->adjustment_remarks = $upperRemark;
            if (in_array($upperRemark, ['O.B.', 'OFFICIAL BUSINESS'], true)) {
                $dtr->status = DailyTimeRecord::STATUS_OFFICIAL_BUSINESS;
            } elseif (in_array($upperRemark, ['O.F.', 'ON FIELD'], true)) {
                $dtr->status = 'O.F.';
            } elseif (in_array($upperRemark, ['RESTDAY', 'REST DAY'], true)) {
                $dtr->status = DailyTimeRecord::STATUS_REST_DAY;
            } elseif ($upperRemark === 'HOLIDAY') {
                $dtr->status = DailyTimeRecord::STATUS_HOLIDAY;
            } elseif ($upperRemark === 'ABSENT') {
                $dtr->status = DailyTimeRecord::STATUS_ABSENT;
            } else {
                $dtr->status = $upperRemark;
            }
            $dtr->rendered_hours = $hasAnyPunches ? min(8.00, ($rendered > 0 ? (float) $rendered : 8.00)) : ($upperRemark === 'ABSENT' ? 0.00 : 8.00);
        } elseif ($hasAnyPunches) {
            $dtr->status = DailyTimeRecord::STATUS_PRESENT;
            $dtr->rendered_hours = min(8.00, (float) ($rendered > 0 ? $rendered : 8.00));
        } else {
            $dtr->status = DailyTimeRecord::STATUS_ABSENT;
            $dtr->rendered_hours = 0.00;
        }

        // Calculate overtime minutes if applicable
        $dtr->overtime_minutes = 0;
        if ($otArrival !== null && $otDeparture !== null) {
            try {
                $otIn = CarbonImmutable::parse($otArrival);
                $otOut = CarbonImmutable::parse($otDeparture);
                if ($otOut->isAfter($otIn)) {
                    $dtr->overtime_minutes = abs($otOut->diffInMinutes($otIn));
                }
            } catch (\Throwable) {
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
