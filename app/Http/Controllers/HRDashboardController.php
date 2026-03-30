<?php

namespace App\Http\Controllers;

use App\Models\COCApplication;
use App\Models\Department;
use App\Models\DepartmentAdmin;
use App\Models\HRAccount;
use App\Models\HrisEmployee;
use App\Models\LeaveApplication;
use App\Models\LeaveApplicationUpdateRequest;
use App\Models\LeaveBalance;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * HR Dashboard — organisation-wide leave application statistics.
 * LOCAL LMS_DB only.
 */
class HRDashboardController extends Controller
{
    /**
     * Dashboard data: leave-focused charts plus summary counts for leave + COC applications.
     */
    public function index(Request $request): JsonResponse
    {
        $hr = $request->user();
        if (!$hr instanceof HRAccount) {
            return response()->json(['message' => 'Only HR accounts can access this endpoint.'], 403);
        }

        $applications = LeaveApplication::with(['leaveType', 'applicantAdmin.department', 'updateRequests'])
            ->where('status', '!=', LeaveApplication::STATUS_PENDING_ADMIN)
            ->orderByDesc('created_at')
            ->get();

        $cocApplications = COCApplication::query()
            ->select(['id', 'status', 'admin_reviewed_at'])
            ->where(function ($query): void {
                $query
                    ->where('status', '!=', COCApplication::STATUS_PENDING)
                    ->orWhereNotNull('admin_reviewed_at');
            })
            ->orderByDesc('created_at')
            ->get();

        $pendingHR = $applications->where('status', LeaveApplication::STATUS_PENDING_HR)->count()
            + $cocApplications->filter(fn (COCApplication $app): bool => $this->deriveCocRawStatus($app) === 'PENDING_HR')->count();
        $totalApproved = $applications->where('status', LeaveApplication::STATUS_APPROVED)->count()
            + $cocApplications->where('status', COCApplication::STATUS_APPROVED)->count();
        $totalRejected = $applications->where('status', LeaveApplication::STATUS_REJECTED)->count()
            + $cocApplications->where('status', COCApplication::STATUS_REJECTED)->count();
        $totalRecalled = $applications->where('status', LeaveApplication::STATUS_RECALLED)->count();
        $totalApplications = $applications->count() + $cocApplications->count();
        $employeeStatusByControlNo = $this->buildEmployeeStatusDirectory(
            $applications
                ->pluck('employee_control_no')
                ->concat($cocApplications->pluck('employee_control_no'))
                ->all()
        );

        // Count employees/admins currently on approved leave today
        $today = now()->toDateString();
        $onLeaveToday = $applications->filter(function ($app) use ($today) {
            if ($app->status !== LeaveApplication::STATUS_APPROVED)
                return false;

            $startStr = $app->start_date ? \Carbon\Carbon::parse($app->start_date)->toDateString() : null;
            $endStr = $app->end_date ? \Carbon\Carbon::parse($app->end_date)->toDateString() : null;

            if (!$startStr || !$endStr)
                return false;

            return $startStr <= $today && $endStr >= $today;
        })->count();

        $formatted = $applications->map(fn($app) => $this->formatApplication($app, $employeeStatusByControlNo));
        $analytics = $this->buildDashboardTrendAnalytics($applications);

        return response()->json([
            'total_count' => $totalApplications,
            'pending_count' => $pendingHR,
            'approved_count' => $totalApproved,
            'rejected_count' => $totalRejected,
            'recalled_count' => $totalRecalled,
            'kpi_breakdown' => [
                'total' => $this->buildEmploymentStatusBreakdown(
                    $applications->concat($cocApplications),
                    $employeeStatusByControlNo
                ),
            ],
            'analytics' => $analytics,
            'on_leave_today' => $onLeaveToday,
            'active_employees' => HrisEmployee::countCached(true),
            'applications' => $formatted,
        ]);
    }

    /**
     * Department Leave Statistics table for the HR dashboard.
     * Returns leave-application counts grouped by office and leave type
     * for the selected period based on approved leave applications only.
     */
    public function departmentStatistics(Request $request): JsonResponse
    {
        $hr = $request->user();
        if (!$hr instanceof HRAccount) {
            return response()->json(['message' => 'Only HR accounts can access this endpoint.'], 403);
        }

        [$periodKey, $periodStart, $periodEnd] = $this->resolveDepartmentStatisticsPeriod(
            (string) $request->query('period', 'daily')
        );

        $activeDepartmentNames = Department::query()
            ->active()
            ->orderBy('name')
            ->pluck('name')
            ->map(fn (mixed $value): string => trim((string) $value))
            ->filter(fn (string $value): bool => $value !== '')
            ->values();

        if ($activeDepartmentNames->isEmpty()) {
            return response()->json([]);
        }

        $rowsByDepartment = $activeDepartmentNames
            ->mapWithKeys(fn (string $departmentName): array => [
                $departmentName => $this->emptyDepartmentStatisticsRow($departmentName),
            ])
            ->all();

        $officeByControlNo = $this->buildDepartmentStatisticsOfficeDirectory();

        $applications = LeaveApplication::query()
            ->with([
                'leaveType:id,name',
                'applicantAdmin.department:id,name',
            ])
            ->where('status', LeaveApplication::STATUS_APPROVED)
            ->whereBetween('created_at', [$periodStart, $periodEnd])
            ->orderBy('created_at')
            ->get([
                'id',
                'employee_control_no',
                'leave_type_id',
                'applicant_admin_id',
                'status',
                'is_monetization',
                'created_at',
            ]);

        foreach ($applications as $application) {
            if ((bool) $application->is_monetization) {
                continue;
            }

            $departmentName = $this->resolveDepartmentStatisticsDepartment($application, $officeByControlNo);
            if ($departmentName === null || !array_key_exists($departmentName, $rowsByDepartment)) {
                continue;
            }

            $leaveTypeColumn = $this->mapDepartmentStatisticsLeaveType($application->leaveType?->name);
            if ($leaveTypeColumn === null) {
                continue;
            }

            $rowsByDepartment[$departmentName][$leaveTypeColumn]++;
        }

        $rows = collect($rowsByDepartment)
            ->filter(function (array $row): bool {
                $countKeys = [
                    'vacationLeave',
                    'sickLeave',
                    'mandatoryForcedLeave',
                    'mco6Leave',
                    'wellnessLeave',
                    'maternityLeave',
                    'paternityLeave',
                    'specialPrivilegeLeave',
                    'soloParentLeave',
                ];

                $total = 0;
                foreach ($countKeys as $countKey) {
                    $total += (int) ($row[$countKey] ?? 0);
                }

                return $total > 0;
            })
            ->values()
            ->all();

        return response()->json($rows);
    }

    private function deriveCocRawStatus(COCApplication $app): string
    {
        if ($app->status !== COCApplication::STATUS_PENDING) {
            return (string) $app->status;
        }

        return $app->admin_reviewed_at ? 'PENDING_HR' : 'PENDING_ADMIN';
    }

    private function resolveDepartmentStatisticsPeriod(string $period): array
    {
        $normalizedPeriod = strtolower(trim($period));
        $now = CarbonImmutable::now();

        return match ($normalizedPeriod) {
            'weekly' => [
                'weekly',
                $now->startOfWeek(),
                $now->endOfWeek(),
            ],
            'monthly' => [
                'monthly',
                $now->startOfMonth(),
                $now->endOfMonth(),
            ],
            'yearly' => [
                'yearly',
                $now->startOfYear(),
                $now->endOfYear(),
            ],
            default => [
                'daily',
                $now->startOfDay(),
                $now->endOfDay(),
            ],
        };
    }

    private function emptyDepartmentStatisticsRow(string $departmentName): array
    {
        return [
            'department' => $departmentName,
            'vacationLeave' => 0,
            'sickLeave' => 0,
            'mandatoryForcedLeave' => 0,
            'mco6Leave' => 0,
            'wellnessLeave' => 0,
            'maternityLeave' => 0,
            'paternityLeave' => 0,
            'specialPrivilegeLeave' => 0,
            'soloParentLeave' => 0,
        ];
    }

    private function buildDepartmentStatisticsOfficeDirectory(): array
    {
        $directory = [];

        foreach (HrisEmployee::allCached() as $employee) {
            $normalizedControlNo = $this->normalizeControlNo($employee->control_no ?? null);
            $office = trim((string) ($employee->office ?? ''));

            if ($normalizedControlNo === '' || $office === '') {
                continue;
            }

            $directory[$normalizedControlNo] = $office;
        }

        return $directory;
    }

    private function resolveDepartmentStatisticsDepartment(LeaveApplication $application, array $officeByControlNo): ?string
    {
        $normalizedControlNo = $this->normalizeControlNo($application->employee_control_no ?? null);
        $office = $officeByControlNo[$normalizedControlNo] ?? null;

        if (is_string($office) && trim($office) !== '') {
            return trim($office);
        }

        $fallbackDepartment = trim((string) ($application->applicantAdmin?->department?->name ?? ''));
        return $fallbackDepartment !== '' ? $fallbackDepartment : null;
    }

    private function mapDepartmentStatisticsLeaveType(?string $leaveTypeName): ?string
    {
        $normalized = strtoupper(trim((string) $leaveTypeName));
        $normalized = preg_replace('/[^A-Z0-9]+/', ' ', $normalized ?? '');
        $normalized = trim((string) $normalized);

        return match ($normalized) {
            'VACATION LEAVE' => 'vacationLeave',
            'SICK LEAVE' => 'sickLeave',
            'MANDATORY FORCED LEAVE', 'MANDATORY LEAVE', 'FORCED LEAVE' => 'mandatoryForcedLeave',
            'MCO6 LEAVE', 'MC06 LEAVE' => 'mco6Leave',
            'WELLNESS LEAVE' => 'wellnessLeave',
            'MATERNITY LEAVE' => 'maternityLeave',
            'PATERNITY LEAVE' => 'paternityLeave',
            'SPECIAL PRIVILEGE LEAVE' => 'specialPrivilegeLeave',
            'SOLO PARENT LEAVE' => 'soloParentLeave',
            default => null,
        };
    }

    private function buildDashboardTrendAnalytics(iterable $applications): array
    {
        $trendYear = (int) now()->year;
        $monthlyTrend = array_fill(0, 12, 0);
        $leaveTypeMonthlyTrend = [];

        foreach ($applications as $application) {
            if (!$application instanceof LeaveApplication) {
                continue;
            }

            if ($application->status !== LeaveApplication::STATUS_APPROVED) {
                continue;
            }

            $trendDate = $this->resolveDashboardTrendDate($application);
            if (!$trendDate || (int) $trendDate->year !== $trendYear) {
                continue;
            }

            $monthIndex = max(0, min(11, (int) $trendDate->month - 1));
            $monthlyTrend[$monthIndex]++;

            $leaveTypeName = $this->resolveDashboardTrendLeaveTypeName($application);
            if (!array_key_exists($leaveTypeName, $leaveTypeMonthlyTrend)) {
                $leaveTypeMonthlyTrend[$leaveTypeName] = array_fill(0, 12, 0);
            }

            $leaveTypeMonthlyTrend[$leaveTypeName][$monthIndex]++;
        }

        if ($leaveTypeMonthlyTrend !== []) {
            ksort($leaveTypeMonthlyTrend, SORT_NATURAL | SORT_FLAG_CASE);
        }

        return [
            'trend_year' => $trendYear,
            'monthly_leave_trend' => $monthlyTrend,
            'leave_type_monthly_trend' => $leaveTypeMonthlyTrend,
        ];
    }

    private function resolveDashboardTrendDate(LeaveApplication $application): ?CarbonImmutable
    {
        $candidates = [
            $application->getAttribute('date_filed'),
            $application->getAttribute('created_at'),
            $application->getAttribute('start_date'),
        ];

        foreach ($candidates as $candidate) {
            if ($candidate === null || $candidate === '') {
                continue;
            }

            try {
                if ($candidate instanceof \DateTimeInterface) {
                    return CarbonImmutable::instance($candidate)->startOfDay();
                }

                return CarbonImmutable::parse((string) $candidate)->startOfDay();
            } catch (\Throwable) {
                continue;
            }
        }

        return null;
    }

    private function resolveDashboardTrendLeaveTypeName(LeaveApplication $application): string
    {
        $name = trim((string) ($application->leaveType?->name ?? ''));
        return $name !== '' ? $name : 'Unknown';
    }

    private function formatApplication(LeaveApplication $app, array $employeeStatusByControlNo = []): array
    {
        $statusMap = [
            LeaveApplication::STATUS_PENDING_ADMIN => 'Pending Admin',
            LeaveApplication::STATUS_PENDING_HR => 'Pending HR',
            LeaveApplication::STATUS_APPROVED => 'Approved',
            LeaveApplication::STATUS_REJECTED => 'Rejected',
            LeaveApplication::STATUS_RECALLED => 'Recalled',
        ];

        // Determine applicant name & office
        $resolvedEmployee = $this->resolveApplicationEmployee($app);
        $employeeName = trim((string) ($app->employee_name ?? ''));
        if ($employeeName === '') {
            $employeeName = $resolvedEmployee
                ? trim(($resolvedEmployee->firstname ?? '') . ' ' . ($resolvedEmployee->surname ?? ''))
                : null;
        }
        $employmentStatus = $employeeStatusByControlNo[$this->normalizeControlNo($app->employee_control_no)] ?? ($resolvedEmployee?->status ?? null);
        $applicantName = $employeeName ?: ($app->applicantAdmin ? $app->applicantAdmin->full_name : 'Unknown');
        $office = $resolvedEmployee?->office ?? ($app->applicantAdmin?->department?->name ?? '');
        $durationDays = (float) $app->total_days;
        $pendingUpdateMeta = $this->resolvePendingUpdateMeta($app);
        $latestUpdateMeta = $this->resolveLatestUpdateMeta($app);
        $selectedDatePayStatus = is_array($app->selected_date_pay_status) ? $app->selected_date_pay_status : null;
        $selectedDateCoverage = is_array($app->selected_date_coverage) ? $app->selected_date_coverage : null;
        $normalizedPayMode = strtoupper(trim((string) ($app->pay_mode ?? LeaveApplication::PAY_MODE_WITH_PAY)));
        if (!in_array($normalizedPayMode, [LeaveApplication::PAY_MODE_WITH_PAY, LeaveApplication::PAY_MODE_WITHOUT_PAY], true)) {
            $normalizedPayMode = LeaveApplication::PAY_MODE_WITH_PAY;
        }
        $deductibleDays = $app->deductible_days !== null
            ? round((float) $app->deductible_days, 2)
            : ($normalizedPayMode === LeaveApplication::PAY_MODE_WITHOUT_PAY ? 0.0 : $durationDays);

        return [
            'id' => $app->id,
            'employee_control_no' => $app->employee_control_no,
            'employeeName' => $applicantName,
            'office' => $office,
            'employment_status' => $employmentStatus,
            'employmentStatus' => $employmentStatus,
            'employee_status' => $employmentStatus,
            'employeeStatus' => $employmentStatus,
            'appointment_status' => $employmentStatus,
            'appointmentStatus' => $employmentStatus,
            'leaveType' => $app->leaveType?->name ?? 'Unknown',
            'startDate' => $app->start_date ? \Carbon\Carbon::parse($app->start_date)->toDateString() : null,
            'endDate' => $app->end_date ? \Carbon\Carbon::parse($app->end_date)->toDateString() : null,
            'days' => $durationDays,
            'duration_value' => $durationDays,
            'duration_unit' => 'day',
            'duration_label' => $durationDays == (int) $durationDays
                ? ((int) $durationDays) . ' ' . ((int) $durationDays === 1 ? 'day' : 'days')
                : $durationDays . ' days',
            'reason' => $app->reason,
            'status' => $statusMap[$app->status] ?? $app->status,
            'rawStatus' => $app->status,
            'remarks' => $app->remarks,
            'pending_update' => $pendingUpdateMeta['payload'],
            'pending_update_reason' => $pendingUpdateMeta['reason'],
            'pending_update_previous_status' => $pendingUpdateMeta['previous_status'],
            'pending_update_requested_by' => $pendingUpdateMeta['requested_by'],
            'pending_update_requested_at' => $pendingUpdateMeta['requested_at']?->toIso8601String(),
            'has_pending_update_request' => ($pendingUpdateMeta['payload'] ?? null) !== null,
            'latest_update_request_status' => $latestUpdateMeta['status'],
            'latest_update_request_payload' => $latestUpdateMeta['payload'],
            'latest_update_request_reason' => $latestUpdateMeta['reason'],
            'latest_update_request_previous_status' => $latestUpdateMeta['previous_status'],
            'latest_update_requested_by' => $latestUpdateMeta['requested_by'],
            'latest_update_requested_at' => $latestUpdateMeta['requested_at']?->toIso8601String(),
            'latest_update_reviewed_at' => $latestUpdateMeta['reviewed_at']?->toIso8601String(),
            'latest_update_review_remarks' => $latestUpdateMeta['review_remarks'],
            'dateFiled' => $app->created_at ? $app->created_at->toDateString() : '',
            'selected_dates' => $app->resolvedSelectedDates(),
            'selected_date_pay_status' => $selectedDatePayStatus,
            'selected_date_coverage' => $selectedDateCoverage,
            'recallEffectiveDate' => $app->recall_effective_date?->toDateString(),
            'recall_effective_date' => $app->recall_effective_date?->toDateString(),
            'recallSelectedDates' => is_array($app->recall_selected_dates) ? array_values($app->recall_selected_dates) : null,
            'recall_selected_dates' => is_array($app->recall_selected_dates) ? array_values($app->recall_selected_dates) : null,
            'pay_mode' => $normalizedPayMode,
            'pay_status' => $normalizedPayMode === LeaveApplication::PAY_MODE_WITHOUT_PAY ? 'Without Pay' : 'With Pay',
            'without_pay' => $normalizedPayMode === LeaveApplication::PAY_MODE_WITHOUT_PAY,
            'with_pay' => $normalizedPayMode !== LeaveApplication::PAY_MODE_WITHOUT_PAY,
            'deductible_days' => $deductibleDays,
            'is_monetization' => (bool) $app->is_monetization,
            'equivalent_amount' => $app->equivalent_amount ? (float) $app->equivalent_amount : null,
            'leaveBalance' => $this->getBalanceForApp($app),
        ];
    }

    private function buildEmployeeStatusDirectory(array $controlNos): array
    {
        $normalizedControlNos = collect($controlNos)
            ->map(fn (mixed $value): string => $this->normalizeControlNo($value))
            ->filter(fn (string $value): bool => $value !== '')
            ->unique()
            ->values()
            ->all();

        if ($normalizedControlNos === []) {
            return [];
        }

        $directory = [];
        foreach (HrisEmployee::allCached() as $employee) {
            $normalizedControlNo = $this->normalizeControlNo($employee->control_no ?? null);
            if ($normalizedControlNo === '' || !in_array($normalizedControlNo, $normalizedControlNos, true)) {
                continue;
            }

            $directory[$normalizedControlNo] = trim((string) ($employee->status ?? '')) ?: null;
        }

        return $directory;
    }

    private function buildEmploymentStatusBreakdown(iterable $applications, array $employeeStatusByControlNo): array
    {
        $breakdown = $this->emptyEmploymentBreakdown();

        foreach ($applications as $application) {
            if (!is_object($application)) {
                continue;
            }

            $bucket = $this->employmentStatusToBucket($application->employee_control_no ?? null, $employeeStatusByControlNo);
            if ($bucket !== null) {
                $breakdown[$bucket]++;
            }
        }

        return $breakdown;
    }

    private function employmentStatusToBucket(mixed $controlNo, array $employeeStatusByControlNo): ?string
    {
        $employeeStatus = $employeeStatusByControlNo[$this->normalizeControlNo($controlNo)] ?? null;
        $rawStatus = strtoupper(trim((string) ($employeeStatus ?? '')));
        if ($rawStatus === '') {
            return null;
        }

        return match ($rawStatus) {
            'ELECTIVE' => 'elective',
            'CO-TERMINOUS', 'CO TERMINOUS', 'COTERMINOUS' => 'co_terminous',
            'REGULAR' => 'regular',
            'CASUAL' => 'casual',
            default => null,
        };
    }

    private function emptyEmploymentBreakdown(): array
    {
        return [
            'elective' => 0,
            'co_terminous' => 0,
            'regular' => 0,
            'casual' => 0,
        ];
    }

    private function normalizeControlNo(mixed $controlNo): string
    {
        $normalized = ltrim(trim((string) ($controlNo ?? '')), '0');
        if ($normalized === '') {
            $normalized = '0';
        }

        return preg_match('/^\d+$/', $normalized) ? $normalized : '';
    }

    private function getPendingApprovedUpdateRequestRecord(LeaveApplication $app): ?LeaveApplicationUpdateRequest
    {
        if (!$app->id) {
            return null;
        }

        if ($app->relationLoaded('updateRequests')) {
            $record = $app->updateRequests
                ->filter(fn($item) => $item instanceof LeaveApplicationUpdateRequest)
                ->sortByDesc(fn(LeaveApplicationUpdateRequest $item) => (int) $item->id)
                ->first(function (LeaveApplicationUpdateRequest $item): bool {
                    return strtoupper(trim((string) $item->status)) === LeaveApplicationUpdateRequest::STATUS_PENDING
                        && strtoupper(trim((string) ($item->previous_status ?? ''))) === LeaveApplication::STATUS_APPROVED;
                });

            return $record instanceof LeaveApplicationUpdateRequest ? $record : null;
        }

        $record = LeaveApplicationUpdateRequest::query()
            ->where('leave_application_id', (int) $app->id)
            ->where('status', LeaveApplicationUpdateRequest::STATUS_PENDING)
            ->latest('id')
            ->first();

        if (!$record) {
            return null;
        }

        $previousStatus = strtoupper(trim((string) ($record->previous_status ?? '')));
        return $previousStatus === LeaveApplication::STATUS_APPROVED ? $record : null;
    }

    private function resolvePendingUpdateMeta(LeaveApplication $app): array
    {
        $pendingRequest = $this->getPendingApprovedUpdateRequestRecord($app);
        if ($pendingRequest instanceof LeaveApplicationUpdateRequest) {
            return [
                'payload' => is_array($pendingRequest->requested_payload) ? $pendingRequest->requested_payload : null,
                'reason' => $this->trimNullableString($pendingRequest->requested_reason),
                'previous_status' => strtoupper(trim((string) ($pendingRequest->previous_status ?? ''))),
                'requested_by' => $this->trimNullableString($pendingRequest->requested_by_control_no),
                'requested_at' => $pendingRequest->requested_at,
            ];
        }

        return [
            'payload' => null,
            'reason' => null,
            'previous_status' => null,
            'requested_by' => null,
            'requested_at' => null,
        ];
    }

    private function getLatestApprovedUpdateRequestRecord(LeaveApplication $app): ?LeaveApplicationUpdateRequest
    {
        if (!$app->id) {
            return null;
        }

        if ($app->relationLoaded('updateRequests')) {
            $record = $app->updateRequests
                ->filter(fn($item) => $item instanceof LeaveApplicationUpdateRequest)
                ->sortByDesc(fn(LeaveApplicationUpdateRequest $item) => (int) $item->id)
                ->first(function (LeaveApplicationUpdateRequest $item): bool {
                    return strtoupper(trim((string) ($item->previous_status ?? ''))) === LeaveApplication::STATUS_APPROVED;
                });

            return $record instanceof LeaveApplicationUpdateRequest ? $record : null;
        }

        $record = LeaveApplicationUpdateRequest::query()
            ->where('leave_application_id', (int) $app->id)
            ->latest('id')
            ->first();

        if (!$record) {
            return null;
        }

        $previousStatus = strtoupper(trim((string) ($record->previous_status ?? '')));
        return $previousStatus === LeaveApplication::STATUS_APPROVED ? $record : null;
    }

    private function resolveLatestUpdateMeta(LeaveApplication $app): array
    {
        $latestRequest = $this->getLatestApprovedUpdateRequestRecord($app);
        if ($latestRequest instanceof LeaveApplicationUpdateRequest) {
            return [
                'status' => strtoupper(trim((string) ($latestRequest->status ?? ''))),
                'payload' => is_array($latestRequest->requested_payload) ? $latestRequest->requested_payload : null,
                'reason' => $this->trimNullableString($latestRequest->requested_reason),
                'previous_status' => strtoupper(trim((string) ($latestRequest->previous_status ?? ''))),
                'requested_by' => $this->trimNullableString($latestRequest->requested_by_control_no),
                'requested_at' => $latestRequest->requested_at,
                'reviewed_at' => $latestRequest->reviewed_at,
                'review_remarks' => $this->trimNullableString($latestRequest->review_remarks),
            ];
        }

        return [
            'status' => null,
            'payload' => null,
            'reason' => null,
            'previous_status' => null,
            'requested_by' => null,
            'requested_at' => null,
            'reviewed_at' => null,
            'review_remarks' => null,
        ];
    }

    private function trimNullableString(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $trimmed = trim((string) $value);
        return $trimmed === '' ? null : $trimmed;
    }

    private function getBalanceForApp(LeaveApplication $app): ?float
    {
        if ($app->employee_control_no) {
            $employeeControlNo = trim((string) $app->employee_control_no);
            $candidateEmployeeControlNos = $this->controlNoCandidates($employeeControlNo);
            if ($candidateEmployeeControlNos === []) {
                return null;
            }

            $balance = LeaveBalance::query()
                ->where('leave_type_id', $app->leave_type_id)
                ->whereIn('employee_control_no', $candidateEmployeeControlNos)
                ->first();
            return $balance ? (float) $balance->balance : null;
        }

        if ($app->applicant_admin_id) {
            $adminControlNo = $this->resolveAdminEmployeeControlNo((int) $app->applicant_admin_id);
            if ($adminControlNo === null) {
                return null;
            }

            $candidateEmployeeControlNos = $this->controlNoCandidates($adminControlNo);
            if ($candidateEmployeeControlNos === []) {
                return null;
            }

            $balance = LeaveBalance::query()
                ->where('leave_type_id', $app->leave_type_id)
                ->whereIn('employee_control_no', $candidateEmployeeControlNos)
                ->first();
            return $balance ? (float) $balance->balance : null;
        }

        return null;
    }

    private function resolveAdminEmployeeControlNo(int $adminId): ?string
    {
        if ($adminId <= 0) {
            return null;
        }

        $admin = DepartmentAdmin::query()->find($adminId);
        if (!$admin) {
            return null;
        }

        $rawControlNo = trim((string) $admin->employee_control_no);
        if ($rawControlNo === '') {
            return null;
        }

        $employee = HrisEmployee::findByControlNo($rawControlNo);
        return trim((string) ($employee?->control_no ?? $rawControlNo));
    }

    private function resolveApplicationEmployee(LeaveApplication $application): ?object
    {
        $controlNo = trim((string) ($application->employee_control_no ?? ''));
        if ($controlNo === '') {
            return null;
        }

        return HrisEmployee::findByControlNo($controlNo);
    }

    private function controlNoCandidates(string $controlNo): array
    {
        $rawControlNo = trim($controlNo);
        if ($rawControlNo === '') {
            return [];
        }

        $normalizedControlNo = ltrim($rawControlNo, '0');
        if ($normalizedControlNo === '') {
            $normalizedControlNo = '0';
        }

        $candidates = [$rawControlNo, $normalizedControlNo];

        $employee = HrisEmployee::findByControlNo($rawControlNo);
        if ($employee && trim((string) $employee->control_no) !== '') {
            $candidates[] = trim((string) $employee->control_no);
        }

        return array_values(array_unique(array_filter($candidates, fn(string $value): bool => $value !== '')));
    }

    /**
     * Calendar endpoint: return approved leaves overlapping a given month.
     * Query params: year (int), month (1-12), department (optional string)
     */
    public function calendarLeaves(Request $request): JsonResponse
    {
        $hr = $request->user();
        if (!$hr instanceof HRAccount) {
            return response()->json(['message' => 'Only HR accounts can access this endpoint.'], 403);
        }

        $year = (int) $request->query('year', now()->year);
        $month = (int) $request->query('month', now()->month);
        $dept = $request->query('department');

        $monthStart = sprintf('%04d-%02d-01', $year, $month);
        $monthEnd = date('Y-m-t', strtotime($monthStart));

        $query = LeaveApplication::with(['leaveType', 'applicantAdmin.department'])
            ->where('status', LeaveApplication::STATUS_APPROVED)
            ->where('start_date', '<=', $monthEnd)
            ->where('end_date', '>=', $monthStart);

        if ($dept) {
            $departmentControlNos = HrisEmployee::controlNosByOffice((string) $dept);
            $departmentControlNoCandidates = collect($departmentControlNos)
                ->flatMap(fn (string $controlNo): array => $this->controlNoCandidates($controlNo))
                ->filter(fn (string $controlNo): bool => $controlNo !== '')
                ->unique()
                ->values()
                ->all();

            $query->where(function ($q) use ($dept, $departmentControlNoCandidates) {
                $q->when(
                    $departmentControlNoCandidates !== [],
                    fn ($nestedQuery) => $nestedQuery->whereIn('employee_control_no', $departmentControlNoCandidates),
                    fn ($nestedQuery) => $nestedQuery->whereRaw('1 = 0')
                )
                    ->orWhereHas('applicantAdmin.department', fn($sq) => $sq->where('name', $dept));
            });
        }

        $applications = $query->orderBy('start_date')->get();

        $formatted = $applications->map(function ($app): array {
            $employee = $this->resolveApplicationEmployee($app);
            $resolvedEmployeeName = trim((string) ($app->employee_name ?? ''));
            if ($resolvedEmployeeName === '') {
                $resolvedEmployeeName = $employee
                    ? trim(($employee->firstname ?? '') . ' ' . ($employee->surname ?? ''))
                    : '';
            }

            return [
                'id' => $app->id,
                'employeeName' => $resolvedEmployeeName !== ''
                    ? $resolvedEmployeeName
                    : ($app->applicantAdmin ? $app->applicantAdmin->full_name : 'Unknown'),
                'employee_control_no' => $app->employee_control_no,
                'office' => $employee?->office ?? ($app->applicantAdmin?->department?->name ?? ''),
                'leaveType' => $app->leaveType?->name ?? 'Unknown',
                'startDate' => $app->start_date ? \Carbon\Carbon::parse($app->start_date)->toDateString() : '',
                'endDate' => $app->end_date ? \Carbon\Carbon::parse($app->end_date)->toDateString() : '',
                'selected_dates' => $app->resolvedSelectedDates(),
                'days' => (float) $app->total_days,
                'duration_value' => (float) $app->total_days,
                'duration_unit' => 'day',
                'duration_label' => ((float) $app->total_days == (int) $app->total_days)
                    ? ((int) $app->total_days) . ' ' . ((int) $app->total_days === 1 ? 'day' : 'days')
                    : ((float) $app->total_days) . ' days',
                'dateFiled' => $app->created_at ? $app->created_at->toDateString() : '',
                'status' => 'Approved',
            ];
        });

        return response()->json(['leaves' => $formatted]);
    }
}
