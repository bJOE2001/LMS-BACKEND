<?php

namespace App\Http\Controllers;

use App\Models\COCApplication;
use App\Models\Department;
use App\Models\HRAccount;
use App\Models\HrisEmployee;
use App\Models\LeaveApplication;
use App\Models\LeaveBalance;
use App\Models\LeaveType;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

class HRReportController extends Controller
{
    private const HOURS_PER_WORKDAY = 8.0;
    private const EMPLOYEE_DIRECTORY_CACHE_KEY = 'hr_reports.employee_directory.v1';

    /**
     * Get summary statistics for HR reports.
     */
    public function getSummaryStats(Request $request): JsonResponse
    {
        if ($response = $this->ensureHr($request)) {
            return $response;
        }

        [$dateFrom, $dateTo] = $this->validateDateRange($request);

        $applicationsQuery = $this->filteredApplicationsQuery($dateFrom, $dateTo);

        $totalApplications = (clone $applicationsQuery)->count();
        $approvedApplicationsQuery = (clone $applicationsQuery)
            ->where('status', LeaveApplication::STATUS_APPROVED);
        $approvedCount = (clone $approvedApplicationsQuery)->count();

        $approvalRate = $totalApplications > 0
            ? round(($approvedCount / $totalApplications) * 100, 1)
            : 0;

        $avgProcessingDays = (clone $approvedApplicationsQuery)
            ->whereNotNull('hr_approved_at')
            ->get(['created_at', 'hr_approved_at'])
            ->avg(static function (LeaveApplication $application): float {
                return (float) $application->created_at?->diffInDays($application->hr_approved_at);
            });

        $activeEmployeesCount = HrisEmployee::countCached(true);

        return response()->json([
            'total_applications' => $totalApplications,
            'approval_rate' => $approvalRate,
            'avg_processing_days' => round($avgProcessingDays ?? 0, 1),
            'active_employees' => $activeEmployeesCount,
        ]);
    }

    /**
     * Get leave statistics per department.
     */
    public function getDepartmentStats(Request $request): JsonResponse
    {
        if ($response = $this->ensureHr($request)) {
            return $response;
        }

        $today = Carbon::today()->toDateString();

        [$dateFrom, $dateTo] = $this->validateDateRange($request);
        $departments = Department::query()->active()->orderBy('name')->get(['id', 'name']);

        $stats = $departments->map(function (Department $department) use ($today, $dateFrom, $dateTo): array {
            $employeeControlNumbers = collect(HrisEmployee::controlNosByOffice($department->name))
                ->flatMap(fn (string $controlNo): array => $this->controlNoCandidates($controlNo))
                ->filter(fn (string $controlNo): bool => $controlNo !== '')
                ->unique()
                ->values();

            $employeeCount = $employeeControlNumbers->count();

            $applicationsQuery = LeaveApplication::query()
                ->where(function (Builder $query) use ($department, $employeeControlNumbers): void {
                    $query->whereHas('applicantAdmin', static function (Builder $adminQuery) use ($department): void {
                        $adminQuery->where('department_id', $department->id);
                    });

                    if ($employeeControlNumbers->isNotEmpty()) {
                        $query->orWhereIn('employee_control_no', $employeeControlNumbers);
                    }
                });

            $this->applyDateRange($applicationsQuery, $dateFrom, $dateTo);

            $applications = $applicationsQuery->get(['status', 'start_date', 'end_date']);

            $onLeaveToday = $applications->filter(static function (LeaveApplication $application) use ($today): bool {
                if ($application->status !== LeaveApplication::STATUS_APPROVED) {
                    return false;
                }

                if (!$application->start_date || !$application->end_date) {
                    return false;
                }

                $start = Carbon::parse($application->start_date)->toDateString();
                $end = Carbon::parse($application->end_date)->toDateString();
                return $today >= $start && $today <= $end;
            })->count();

            $pending = $applications->whereIn('status', [
                LeaveApplication::STATUS_PENDING_ADMIN,
                LeaveApplication::STATUS_PENDING_HR,
            ])->count();

            $approved = $applications->where('status', LeaveApplication::STATUS_APPROVED)->count();

            $utilization = $employeeCount > 0
                ? round(($approved / $employeeCount) * 100, 1)
                : 0;

            return [
                'dept' => $department->name,
                'total' => $employeeCount,
                'onLeave' => $onLeaveToday,
                'pending' => $pending,
                'approved' => $approved,
                'rate' => $utilization,
            ];
        });

        return response()->json($stats->values());
    }

    /**
     * Get usage statistics by leave type.
     */
    public function getLeaveTypeStats(Request $request): JsonResponse
    {
        if ($response = $this->ensureHr($request)) {
            return $response;
        }

        [$dateFrom, $dateTo] = $this->validateDateRange($request);

        $approvedCountByLeaveType = $this->filteredApplicationsQuery($dateFrom, $dateTo)
            ->where('status', LeaveApplication::STATUS_APPROVED)
            ->selectRaw('leave_type_id, COUNT(*) as total')
            ->groupBy('leave_type_id')
            ->pluck('total', 'leave_type_id');

        $stats = LeaveType::query()
            ->orderBy('name')
            ->get(['id', 'name'])
            ->map(static function (LeaveType $type) use ($approvedCountByLeaveType): array {
                return [
                    'name' => $type->name,
                    'count' => (int) $approvedCountByLeaveType->get($type->id, 0),
                ];
            });

        return response()->json($stats->values());
    }

    /**
     * Generate report data based on filters.
     */
    public function generateReport(Request $request): JsonResponse
    {
        if ($response = $this->ensureHr($request)) {
            return $response;
        }

        $validated = $request->validate([
            'type' => 'required|string',
            'date_from' => 'nullable|date',
            'date_to' => 'nullable|date|after_or_equal:date_from',
        ]);

        [$dateFrom, $dateTo] = $this->toDateRange($validated);

        $query = LeaveApplication::with(['leaveType', 'applicantAdmin.department'])
            ->orderByDesc('created_at');

        $this->applyDateRange($query, $dateFrom, $dateTo);

        $applications = $query->get()->map(function (LeaveApplication $application): array {
            $employee = $this->resolveApplicationEmployee($application);
            $applicantName = $employee
                ? trim(($employee->firstname ?? '') . ' ' . ($employee->surname ?? ''))
                : ($application->applicantAdmin ? $application->applicantAdmin->full_name : 'Unknown');
            $deptName = $employee?->office ?? $application->applicantAdmin?->department?->name ?? 'N/A';

            return [
                'id' => $application->id,
                'applicant' => $applicantName,
                'department' => $deptName,
                'leave_type' => $application->leaveType?->name ?? 'Unknown',
                'start_date' => Carbon::parse($application->start_date)->toDateString(),
                'end_date' => Carbon::parse($application->end_date)->toDateString(),
                'days' => (float) $application->total_days,
                'status' => $application->status,
                'date_filed' => $application->created_at?->toDateString(),
            ];
        });

        return response()->json($applications);
    }

    public function lwopReports(Request $request): JsonResponse
    {
        if ($response = $this->ensureHr($request)) {
            return $response;
        }

        $applications = LeaveApplication::query()
            ->with(['leaveType', 'applicantAdmin.department'])
            ->where('is_monetization', false)
            ->where('status', LeaveApplication::STATUS_APPROVED)
            ->orderBy('start_date')
            ->orderBy('created_at')
            ->get([
                'id',
                'employee_control_no',
                'employee_name',
                'leave_type_id',
                'start_date',
                'end_date',
                'selected_dates',
                'selected_date_pay_status',
                'total_days',
                'deductible_days',
                'pay_mode',
                'remarks',
                'created_at',
                'admin_approved_at',
                'hr_approved_at',
                'applicant_admin_id',
            ]);

        $employeeDirectory = $this->getEmployeeDirectory();
        $rows = [];

        foreach ($applications as $application) {
            [, $withoutPayDays] = $this->resolveApplicationPayBreakdown($application);
            if ($withoutPayDays <= 0) {
                continue;
            }

            $employee = $this->resolveApplicationEmployeeProfile($application, $employeeDirectory);
            $wopDateKeys = $this->resolveApplicationWopDateKeys($application);
            $referenceDate = $this->resolveReferenceDateFromDateKeys($wopDateKeys)
                ?? $this->resolveApplicationReferenceDate($application)
                ?? $application->created_at;
            $receivedAt = $application->admin_approved_at ?? $application->hr_approved_at ?? $application->created_at;
            $monthYear = $this->resolveMonthYear($referenceDate);

            $rows[] = [
                'name' => $employee['name'],
                'office' => $employee['office'],
                'status' => $employee['status'],
                'periodIncurred' => $this->formatDateSummaryFromDateKeys($wopDateKeys)
                    ?: $this->formatApplicationInclusiveDates($application),
                'typeOfLeave' => $application->leaveType?->name ?? 'Leave',
                'totalDaysLWOP' => $withoutPayDays,
                'dateReceivedCHRMO' => $this->formatDateForDisplay($receivedAt),
                'remarks' => $this->trimNullableString($application->remarks) ?? '',
                'month' => $monthYear['month'],
                'year' => $monthYear['year'],
                '_sort_key' => $this->composeSortKey([
                    $employee['office'],
                    $employee['name'],
                    $this->formatSortableDate($referenceDate),
                    (string) $application->id,
                ]),
            ];
        }

        return response()->json($this->finalizeRows($rows));
    }

    public function leaveBalancesReports(Request $request): JsonResponse
    {
        if ($response = $this->ensureHr($request)) {
            return $response;
        }

        $employeeDirectory = $this->getEmployeeDirectory();
        $now = Carbon::today();
        $currentYear = (int) $now->year;

        $creditBasedTypes = LeaveType::query()
            ->where('is_credit_based', true)
            ->orderBy('name')
            ->get(['id', 'name']);
        $creditTypeIds = $creditBasedTypes->pluck('id')->map(fn (mixed $id): int => (int) $id)->all();
        $creditTypeNamesById = $creditBasedTypes
            ->mapWithKeys(fn (LeaveType $type): array => [(int) $type->id => (string) $type->name])
            ->all();

        $balanceRows = LeaveBalance::query()
            ->whereIn('leave_type_id', $creditTypeIds)
            ->orderBy('employee_control_no')
            ->get([
                'employee_control_no',
                'employee_name',
                'leave_type_id',
                'balance',
            ]);

        $applications = LeaveApplication::query()
            ->with('leaveType:id,name')
            ->whereIn('status', [LeaveApplication::STATUS_APPROVED, LeaveApplication::STATUS_RECALLED])
            ->where('is_monetization', false)
            ->orderBy('start_date')
            ->orderBy('created_at')
            ->get([
                'id',
                'employee_control_no',
                'employee_name',
                'leave_type_id',
                'start_date',
                'end_date',
                'selected_dates',
                'recall_selected_dates',
                'status',
                'total_days',
                'created_at',
            ]);

        $aggregates = [];

        foreach ($balanceRows as $balanceRow) {
            $controlNo = trim((string) $balanceRow->employee_control_no);
            $employeeKey = $this->normalizeControlNoKey($controlNo);
            if ($employeeKey === '') {
                continue;
            }

            $aggregate = &$aggregates[$employeeKey];
            if (!is_array($aggregate)) {
                $aggregate = $this->emptyLeaveBalanceAggregate($controlNo, (string) ($balanceRow->employee_name ?? ''));
            }

            $aggregate['control_no'] = $aggregate['control_no'] !== '' ? $aggregate['control_no'] : $controlNo;
            if ($aggregate['fallback_name'] === '') {
                $aggregate['fallback_name'] = trim((string) ($balanceRow->employee_name ?? ''));
            }

            $amount = round((float) ($balanceRow->balance ?? 0), 2);
            $bucket = $this->classifyLeaveBalanceType($creditTypeNamesById[(int) $balanceRow->leave_type_id] ?? null);

            match ($bucket) {
                'vacation' => $aggregate['balanceVl'] += $amount,
                'sick' => $aggregate['balanceSl'] += $amount,
                'forced' => $aggregate['balanceFl'] += $amount,
                'mc_co' => $aggregate['balanceMcCo'] += $amount,
                'wlp' => $aggregate['balanceWlp'] += $amount,
                default => $aggregate['balanceOthers'] += $amount,
            };
        }
        unset($aggregate);

        foreach ($applications as $application) {
            $referenceDate = $this->resolveApplicationReferenceDate($application);
            if (!$referenceDate || (int) $referenceDate->year !== $currentYear) {
                continue;
            }

            $controlNo = trim((string) $application->employee_control_no);
            $employeeKey = $this->normalizeControlNoKey($controlNo);
            if ($employeeKey === '') {
                continue;
            }

            $aggregate = &$aggregates[$employeeKey];
            if (!is_array($aggregate)) {
                $aggregate = $this->emptyLeaveBalanceAggregate($controlNo, (string) ($application->employee_name ?? ''));
            }

            $aggregate['control_no'] = $aggregate['control_no'] !== '' ? $aggregate['control_no'] : $controlNo;
            if ($aggregate['fallback_name'] === '') {
                $aggregate['fallback_name'] = trim((string) ($application->employee_name ?? ''));
            }

            $days = $this->resolveOfficeLeaveAvailmentDays($application);
            if ($days <= 0) {
                continue;
            }

            $bucket = $this->classifyLeaveAvailmentType($application->leaveType?->name ?? null);
            match ($bucket) {
                'vl_fl' => $aggregate['daysVlFl'] += $days,
                'sl' => $aggregate['daysSl'] += $days,
                'mc_co' => $aggregate['daysMcCo'] += $days,
                'wlp' => $aggregate['daysWlp'] += $days,
                default => $aggregate['daysOthers'] += $days,
            };
        }
        unset($aggregate);

        $rows = [];
        foreach ($aggregates as $aggregate) {
            $employee = $this->resolveEmployeeProfile(
                $aggregate['control_no'],
                $aggregate['fallback_name'],
                null,
                $employeeDirectory
            );

            $balanceVl = round((float) $aggregate['balanceVl'], 2);
            $balanceSl = round((float) $aggregate['balanceSl'], 2);
            $balanceFl = round((float) $aggregate['balanceFl'], 2);
            $balanceMcCo = round((float) $aggregate['balanceMcCo'], 2);
            $balanceWlp = round((float) $aggregate['balanceWlp'], 2);
            $balanceOthers = round((float) $aggregate['balanceOthers'], 2);

            $rows[] = [
                'name' => $employee['name'],
                'designation' => $employee['designation'],
                'status' => $employee['status'],
                'office' => $employee['office'],
                'runningBalanceVl' => $balanceVl,
                'runningBalanceSl' => $balanceSl,
                'annualBalanceMcCo' => $balanceMcCo,
                'annualBalanceWlp' => $balanceWlp,
                'annualBalanceOthers' => $balanceOthers,
                'daysVlFl' => round((float) $aggregate['daysVlFl'], 2),
                'daysSl' => round((float) $aggregate['daysSl'], 2),
                'daysMcCo' => round((float) $aggregate['daysMcCo'], 2),
                'daysWlp' => round((float) $aggregate['daysWlp'], 2),
                'daysOthers' => round((float) $aggregate['daysOthers'], 2),
                'balanceVl' => $balanceVl,
                'balanceSl' => $balanceSl,
                'balanceFl' => $balanceFl,
                'balanceMcCo' => $balanceMcCo,
                'balanceWlp' => $balanceWlp,
                'balanceOthers' => $balanceOthers,
                'totalNoLeave' => round(
                    (float) $aggregate['daysVlFl']
                    + (float) $aggregate['daysSl']
                    + (float) $aggregate['daysMcCo']
                    + (float) $aggregate['daysWlp']
                    + (float) $aggregate['daysOthers'],
                    2
                ),
                'remarks' => '',
                'month' => (int) $now->month,
                'year' => (int) $now->year,
                '_sort_key' => $this->composeSortKey([
                    $employee['office'],
                    $employee['name'],
                    $aggregate['control_no'],
                ]),
            ];
        }

        return response()->json($this->finalizeRows($rows));
    }

    public function monetizationReports(Request $request): JsonResponse
    {
        if ($response = $this->ensureHr($request)) {
            return $response;
        }

        $applications = LeaveApplication::query()
            ->with(['leaveType', 'applicantAdmin.department'])
            ->where('is_monetization', true)
            ->where('status', LeaveApplication::STATUS_APPROVED)
            ->orderBy('created_at')
            ->get([
                'id',
                'employee_control_no',
                'employee_name',
                'total_days',
                'deductible_days',
                'remarks',
                'equivalent_amount',
                'created_at',
                'admin_approved_at',
                'hr_approved_at',
                'applicant_admin_id',
            ]);

        $employeeDirectory = $this->getEmployeeDirectory();
        $rows = [];

        foreach ($applications as $application) {
            $employee = $this->resolveApplicationEmployeeProfile($application, $employeeDirectory);
            $receivedAt = $application->admin_approved_at ?? $application->hr_approved_at ?? $application->created_at;
            $referenceDate = $receivedAt ?? $application->created_at;
            $monthYear = $this->resolveMonthYear($referenceDate);
            $remarks = $this->trimNullableString($application->remarks);

            if ($remarks === null && $application->equivalent_amount !== null) {
                $remarks = 'Equivalent amount: ' . number_format((float) $application->equivalent_amount, 2);
            }

            $rows[] = [
                'dateReceivedHRMO' => $this->formatDateForDisplay($receivedAt),
                'dateOfFiling' => $this->formatDateForDisplay($application->created_at),
                'name' => $employee['name'],
                'designation' => $employee['designation'],
                'status' => $employee['status'],
                'office' => $employee['office'],
                'totalDays' => round((float) ($application->deductible_days ?? $application->total_days ?? 0), 2),
                'remarks' => $remarks ?? '',
                'month' => $monthYear['month'],
                'year' => $monthYear['year'],
                '_sort_key' => $this->composeSortKey([
                    $this->formatSortableDate($referenceDate),
                    $employee['office'],
                    $employee['name'],
                    (string) $application->id,
                ]),
            ];
        }

        return response()->json($this->finalizeRows($rows));
    }

    public function ctoAvailmentReports(Request $request): JsonResponse
    {
        if ($response = $this->ensureHr($request)) {
            return $response;
        }

        $ctoLeaveTypeId = $this->resolveCtoLeaveTypeId();
        if ($ctoLeaveTypeId === null) {
            return response()->json([]);
        }

        $applications = LeaveApplication::query()
            ->with(['leaveType', 'applicantAdmin.department'])
            ->where('is_monetization', false)
            ->where('status', LeaveApplication::STATUS_APPROVED)
            ->where('leave_type_id', $ctoLeaveTypeId)
            ->orderBy('start_date')
            ->orderBy('created_at')
            ->get([
                'id',
                'employee_control_no',
                'employee_name',
                'start_date',
                'end_date',
                'selected_dates',
                'total_days',
                'remarks',
                'created_at',
                'applicant_admin_id',
            ]);

        $employeeDirectory = $this->getEmployeeDirectory();
        $approvedCocApplications = COCApplication::query()
            ->where('status', COCApplication::STATUS_APPROVED)
            ->whereNotNull('cto_credited_days')
            ->where('cto_credited_days', '>', 0)
            ->orderBy('cto_credited_at')
            ->orderBy('reviewed_at')
            ->orderBy('created_at')
            ->get([
                'id',
                'employee_control_no',
                'total_minutes',
                'cto_credited_at',
                'reviewed_at',
                'created_at',
            ]);

        $creditEventsByEmployee = [];
        foreach ($approvedCocApplications as $cocApplication) {
            $employeeKey = $this->normalizeControlNoKey((string) $cocApplication->employee_control_no);
            if ($employeeKey === '') {
                continue;
            }

            $creditedAt = $cocApplication->cto_credited_at ?? $cocApplication->reviewed_at ?? $cocApplication->created_at;
            if (!$creditedAt) {
                continue;
            }

            $creditEventsByEmployee[$employeeKey][] = [
                'id' => (int) $cocApplication->id,
                'credited_at' => $creditedAt->copy(),
                'hours' => $this->minutesToHours((int) $cocApplication->total_minutes),
            ];
        }

        $approvedCtoRowsByEmployee = [];
        foreach ($applications as $application) {
            $employeeKey = $this->normalizeControlNoKey((string) $application->employee_control_no);
            if ($employeeKey === '') {
                continue;
            }

            $referenceDate = $this->resolveApplicationReferenceDate($application) ?? $application->created_at;
            if (!$referenceDate) {
                continue;
            }

            $approvedCtoRowsByEmployee[$employeeKey][] = [
                'id' => (int) $application->id,
                'reference_date' => $referenceDate->copy(),
                'hours' => round($this->resolveReportableApplicationDays($application) * self::HOURS_PER_WORKDAY, 2),
            ];
        }

        $rows = [];
        foreach ($applications as $application) {
            $employee = $this->resolveApplicationEmployeeProfile($application, $employeeDirectory);
            $employeeKey = $this->normalizeControlNoKey((string) $application->employee_control_no);
            if ($employeeKey === '') {
                continue;
            }

            $referenceDate = $this->resolveApplicationReferenceDate($application) ?? $application->created_at;
            if (!$referenceDate) {
                continue;
            }

            $earnedHoursAsOf = 0.0;
            foreach ($creditEventsByEmployee[$employeeKey] ?? [] as $creditEvent) {
                if ($creditEvent['credited_at']->lte($referenceDate)) {
                    $earnedHoursAsOf += (float) $creditEvent['hours'];
                }
            }

            $priorDeductedHours = 0.0;
            foreach ($approvedCtoRowsByEmployee[$employeeKey] ?? [] as $approvedCtoRow) {
                $isEarlierApplication = $approvedCtoRow['reference_date']->lt($referenceDate)
                    || (
                        $approvedCtoRow['reference_date']->equalTo($referenceDate)
                        && (int) $approvedCtoRow['id'] < (int) $application->id
                    );

                if ($isEarlierApplication) {
                    $priorDeductedHours += (float) $approvedCtoRow['hours'];
                }
            }

            $runningBalance = round(max($earnedHoursAsOf - $priorDeductedHours, 0), 2);
            $hoursFiled = round($this->resolveReportableApplicationDays($application) * self::HOURS_PER_WORKDAY, 2);
            $monthYear = $this->resolveMonthYear($referenceDate);

            $rows[] = [
                'dateFiled' => $this->formatDateForDisplay($application->created_at),
                'name' => $employee['name'],
                'designation' => $employee['designation'],
                'office' => $employee['office'],
                'status' => $employee['status'],
                'totalDaysApplied' => round($this->resolveReportableApplicationDays($application), 2),
                'inclusiveDates' => $this->formatApplicationInclusiveDates($application),
                'earnedCocHoursAsOf' => round($earnedHoursAsOf, 2),
                'runningCocBalance' => $runningBalance,
                'totalHoursFiled' => $hoursFiled,
                'cocBalanceApproved' => round(max($runningBalance - $hoursFiled, 0), 2),
                'remarks' => $this->trimNullableString($application->remarks) ?? '',
                'month' => $monthYear['month'],
                'year' => $monthYear['year'],
                '_sort_key' => $this->composeSortKey([
                    $this->formatSortableDate($referenceDate),
                    $employee['office'],
                    $employee['name'],
                    (string) $application->id,
                ]),
            ];
        }

        return response()->json($this->finalizeRows($rows));
    }

    public function cocBalanceReports(Request $request): JsonResponse
    {
        if ($response = $this->ensureHr($request)) {
            return $response;
        }

        $ctoLeaveTypeId = $this->resolveCtoLeaveTypeId();
        if ($ctoLeaveTypeId === null) {
            return response()->json([]);
        }

        $employeeDirectory = $this->getEmployeeDirectory();
        $now = Carbon::today();

        $currentBalances = LeaveBalance::query()
            ->where('leave_type_id', $ctoLeaveTypeId)
            ->orderBy('employee_control_no')
            ->get([
                'employee_control_no',
                'employee_name',
                'balance',
            ]);

        $approvedCocApplications = COCApplication::query()
            ->where('status', COCApplication::STATUS_APPROVED)
            ->whereNotNull('cto_credited_days')
            ->where('cto_credited_days', '>', 0)
            ->orderBy('cto_credited_at')
            ->orderBy('reviewed_at')
            ->orderBy('created_at')
            ->get([
                'employee_control_no',
                'employee_name',
                'total_minutes',
                'cto_credited_at',
                'reviewed_at',
                'created_at',
            ]);

        $approvedCtoApplications = LeaveApplication::query()
            ->with('leaveType:id,name')
            ->where('is_monetization', false)
            ->whereIn('status', [LeaveApplication::STATUS_APPROVED, LeaveApplication::STATUS_RECALLED])
            ->where('leave_type_id', $ctoLeaveTypeId)
            ->orderBy('start_date')
            ->orderBy('created_at')
            ->get([
                'id',
                'employee_control_no',
                'employee_name',
                'leave_type_id',
                'start_date',
                'end_date',
                'selected_dates',
                'recall_selected_dates',
                'status',
                'total_days',
                'created_at',
            ]);

        $aggregates = [];

        foreach ($currentBalances as $balanceRow) {
            $controlNo = trim((string) $balanceRow->employee_control_no);
            $employeeKey = $this->normalizeControlNoKey($controlNo);
            if ($employeeKey === '') {
                continue;
            }

            $aggregate = &$aggregates[$employeeKey];
            if (!is_array($aggregate)) {
                $aggregate = [
                    'control_no' => $controlNo,
                    'fallback_name' => trim((string) ($balanceRow->employee_name ?? '')),
                    'balance_hours_fallback' => 0.0,
                    'earned_hours' => 0.0,
                    'deducted_hours' => 0.0,
                    'has_exact_history' => false,
                    'earned_dates' => [],
                    'expiry_dates' => [],
                    'latest_earned_at' => null,
                ];
            }

            $aggregate['balance_hours_fallback'] += round((float) ($balanceRow->balance ?? 0) * self::HOURS_PER_WORKDAY, 2);
        }
        unset($aggregate);
        foreach ($approvedCocApplications as $cocApplication) {
            $controlNo = trim((string) $cocApplication->employee_control_no);
            $employeeKey = $this->normalizeControlNoKey($controlNo);
            if ($employeeKey === '') {
                continue;
            }

            $aggregate = &$aggregates[$employeeKey];
            if (!is_array($aggregate)) {
                $aggregate = [
                    'control_no' => $controlNo,
                    'fallback_name' => trim((string) ($cocApplication->employee_name ?? '')),
                    'balance_hours_fallback' => 0.0,
                    'earned_hours' => 0.0,
                    'deducted_hours' => 0.0,
                    'has_exact_history' => false,
                    'earned_dates' => [],
                    'expiry_dates' => [],
                    'latest_earned_at' => null,
                ];
            }

            $aggregate['earned_hours'] += $this->minutesToHours((int) ($cocApplication->total_minutes ?? 0));
            $aggregate['has_exact_history'] = true;

            $creditedAt = $cocApplication->cto_credited_at ?? $cocApplication->reviewed_at ?? $cocApplication->created_at;
            if ($creditedAt) {
                $aggregate['earned_dates'][] = $creditedAt->copy();
                $aggregate['expiry_dates'][] = $creditedAt->copy()->addYearNoOverflow();

                if (!$aggregate['latest_earned_at'] || $creditedAt->gt($aggregate['latest_earned_at'])) {
                    $aggregate['latest_earned_at'] = $creditedAt->copy();
                }
            }
        }
        unset($aggregate);

        foreach ($approvedCtoApplications as $application) {
            $controlNo = trim((string) $application->employee_control_no);
            $employeeKey = $this->normalizeControlNoKey($controlNo);
            if ($employeeKey === '') {
                continue;
            }

            $aggregate = &$aggregates[$employeeKey];
            if (!is_array($aggregate)) {
                $aggregate = [
                    'control_no' => $controlNo,
                    'fallback_name' => trim((string) ($application->employee_name ?? '')),
                    'balance_hours_fallback' => 0.0,
                    'earned_hours' => 0.0,
                    'deducted_hours' => 0.0,
                    'has_exact_history' => false,
                    'earned_dates' => [],
                    'expiry_dates' => [],
                    'latest_earned_at' => null,
                ];
            }

            $aggregate['deducted_hours'] += round(
                $this->resolveReportableApplicationDays($application) * self::HOURS_PER_WORKDAY,
                2
            );
        }
        unset($aggregate);

        $rows = [];
        foreach ($aggregates as $aggregate) {
            $employee = $this->resolveEmployeeProfile(
                $aggregate['control_no'],
                $aggregate['fallback_name'],
                null,
                $employeeDirectory
            );

            $referenceDate = $aggregate['latest_earned_at'] instanceof Carbon
                ? $aggregate['latest_earned_at']
                : $now;
            $monthYear = $this->resolveMonthYear($referenceDate);
            $totalBalanceHours = (bool) ($aggregate['has_exact_history'] ?? false)
                ? round(max((float) $aggregate['earned_hours'] - (float) $aggregate['deducted_hours'], 0), 2)
                : round((float) ($aggregate['balance_hours_fallback'] ?? 0), 2);

            $rows[] = [
                'name' => $employee['name'],
                'designation' => $employee['designation'],
                'status' => $employee['status'],
                'office' => $employee['office'],
                'totalBalanceHours' => $totalBalanceHours,
                'monthYearEarned' => $this->formatMonthYearList($aggregate['earned_dates']),
                'monthYearExpired' => $this->formatMonthYearList($aggregate['expiry_dates']),
                'remarks' => $totalBalanceHours > 0 ? 'Current available COC balance' : 'No remaining COC balance',
                'month' => $monthYear['month'],
                'year' => $monthYear['year'],
                '_sort_key' => $this->composeSortKey([
                    $employee['office'],
                    $employee['name'],
                    $aggregate['control_no'],
                ]),
            ];
        }

        return response()->json($this->finalizeRows($rows));
    }

    public function leaveAvailmentReports(Request $request): JsonResponse
    {
        if ($response = $this->ensureHr($request)) {
            return $response;
        }

        $applications = LeaveApplication::query()
            ->with(['leaveType', 'applicantAdmin.department'])
            ->where('is_monetization', false)
            ->whereIn('status', [LeaveApplication::STATUS_APPROVED, LeaveApplication::STATUS_RECALLED])
            ->orderBy('start_date')
            ->orderBy('created_at')
            ->get([
                'id',
                'employee_control_no',
                'employee_name',
                'leave_type_id',
                'start_date',
                'end_date',
                'selected_dates',
                'recall_selected_dates',
                'status',
                'total_days',
                'created_at',
            ]);

        $employeeDirectory = $this->getEmployeeDirectory();
        $aggregates = [];

        foreach ($applications as $application) {
            $referenceDate = $this->resolveApplicationReferenceDate($application) ?? $application->created_at;
            if (!$referenceDate) {
                continue;
            }

            $controlNo = trim((string) $application->employee_control_no);
            $employeeKey = $this->normalizeControlNoKey($controlNo);
            if ($employeeKey === '') {
                continue;
            }

            $month = (int) $referenceDate->month;
            $year = (int) $referenceDate->year;
            $periodKey = sprintf('%04d-%02d', $year, $month);

            if (!array_key_exists($employeeKey, $aggregates)) {
                $aggregates[$employeeKey] = [
                    'control_no' => $controlNo,
                    'fallback_name' => trim((string) ($application->employee_name ?? '')),
                    'vlFl' => 0.0,
                    'sl' => 0.0,
                    'mcCo' => 0.0,
                    'wlp' => 0.0,
                    'others' => 0.0,
                    'months' => [],
                    'years' => [],
                    'periodKeys' => [],
                    'latest_reference_date' => $referenceDate->copy(),
                ];
            }

            $aggregates[$employeeKey]['months'][(string) $month] = $month;
            $aggregates[$employeeKey]['years'][(string) $year] = $year;
            $aggregates[$employeeKey]['periodKeys'][$periodKey] = $periodKey;

            if ($referenceDate->gt($aggregates[$employeeKey]['latest_reference_date'])) {
                $aggregates[$employeeKey]['latest_reference_date'] = $referenceDate->copy();
            }

            $days = $this->resolveOfficeLeaveAvailmentDays($application);
            if ($days <= 0) {
                continue;
            }

            $bucket = $this->classifyOfficeLeaveAvailmentType($application->leaveType?->name ?? null);
            if ($bucket === null) {
                continue;
            }

            match ($bucket) {
                'vl_fl' => $aggregates[$employeeKey]['vlFl'] += $days,
                'sl' => $aggregates[$employeeKey]['sl'] += $days,
                'mc_co' => $aggregates[$employeeKey]['mcCo'] += $days,
                'wlp' => $aggregates[$employeeKey]['wlp'] += $days,
                default => $aggregates[$employeeKey]['others'] += $days,
            };
        }

        $rows = [];
        foreach ($aggregates as $aggregate) {
            $employee = $this->resolveEmployeeProfile(
                $aggregate['control_no'],
                $aggregate['fallback_name'],
                null,
                $employeeDirectory
            );

            $vlFl = round((float) $aggregate['vlFl'], 2);
            $sl = round((float) $aggregate['sl'], 2);
            $mcCo = round((float) $aggregate['mcCo'], 2);
            $wlp = round((float) $aggregate['wlp'], 2);
            $others = round((float) $aggregate['others'], 2);
            $months = array_values($aggregate['months']);
            sort($months);
            $years = array_values($aggregate['years']);
            rsort($years);
            $periodKeys = array_values($aggregate['periodKeys']);
            sort($periodKeys);
            $latestReferenceDate = $aggregate['latest_reference_date'] instanceof Carbon
                ? $aggregate['latest_reference_date']
                : Carbon::today();

            $rows[] = [
                'name' => $employee['name'],
                'designation' => $employee['designation'],
                'status' => $employee['status'],
                'office' => $employee['office'],
                'vlFl' => $vlFl,
                'sl' => $sl,
                'mcCo' => $mcCo,
                'wlp' => $wlp,
                'others' => $others,
                'totalNoLeave' => round($vlFl + $sl + $mcCo + $wlp + $others, 2),
                'remarks' => '',
                'month' => (int) $latestReferenceDate->month,
                'year' => (int) $latestReferenceDate->year,
                'months' => $months,
                'years' => $years,
                'periodKeys' => $periodKeys,
                '_sort_key' => $this->composeSortKey([
                    $employee['office'],
                    $employee['name'],
                    $aggregate['control_no'],
                ]),
            ];
        }

        return response()->json($this->finalizeRows($rows));
    }

    /**
     * @return array{0: Carbon|null, 1: Carbon|null}
     */
    private function validateDateRange(Request $request): array
    {
        $validated = $request->validate([
            'date_from' => 'nullable|date',
            'date_to' => 'nullable|date|after_or_equal:date_from',
        ]);

        return $this->toDateRange($validated);
    }

    /**
     * @param array{date_from?: string|null, date_to?: string|null} $validated
     * @return array{0: Carbon|null, 1: Carbon|null}
     */
    private function toDateRange(array $validated): array
    {
        $dateFrom = isset($validated['date_from']) && $validated['date_from']
            ? Carbon::parse($validated['date_from'])->startOfDay()
            : null;

        $dateTo = isset($validated['date_to']) && $validated['date_to']
            ? Carbon::parse($validated['date_to'])->endOfDay()
            : null;

        return [$dateFrom, $dateTo];
    }

    private function filteredApplicationsQuery(?Carbon $dateFrom, ?Carbon $dateTo): Builder
    {
        $query = LeaveApplication::query();
        return $this->applyDateRange($query, $dateFrom, $dateTo);
    }

    private function applyDateRange(Builder $query, ?Carbon $dateFrom, ?Carbon $dateTo): Builder
    {
        if ($dateFrom) {
            $query->where('created_at', '>=', $dateFrom);
        }

        if ($dateTo) {
            $query->where('created_at', '<=', $dateTo);
        }

        return $query;
    }

    private function resolveApplicationEmployee(LeaveApplication $application): ?object
    {
        $controlNo = trim((string) ($application->employee_control_no ?? ''));
        if ($controlNo === '') {
            return null;
        }

        return HrisEmployee::findByControlNo($controlNo);
    }

    /**
     * @return array<int, string>
     */
    private function controlNoCandidates(string $controlNo): array
    {
        $controlNo = trim($controlNo);
        if ($controlNo === '') {
            return [];
        }

        $normalized = ltrim($controlNo, '0');
        if ($normalized === '') {
            $normalized = '0';
        }

        return array_values(array_unique(array_filter([
            $controlNo,
            $normalized,
        ], static fn (string $value): bool => $value !== '')));
    }

    private function ensureHr(Request $request): ?JsonResponse
    {
        if (!$request->user() instanceof HRAccount) {
            return response()->json([
                'message' => 'Only HR accounts can access this resource.',
            ], 403);
        }

        return null;
    }

    private function getEmployeeDirectory(): array
    {
        return Cache::remember(self::EMPLOYEE_DIRECTORY_CACHE_KEY, now()->addMinutes(5), function (): array {
            $byRaw = [];
            $byKey = [];

            foreach (HrisEmployee::allCached() as $employee) {
                $controlNo = trim((string) ($employee->control_no ?? ''));
                if ($controlNo === '') {
                    continue;
                }

                $entry = [
                    'control_no' => $controlNo,
                    'name' => $this->buildEmployeeNameFromSnapshot($employee) ?? $controlNo,
                    'office' => $this->trimNullableString($employee->office ?? null) ?? '',
                    'status' => $this->formatEmploymentStatus($employee->status ?? null),
                    'designation' => $this->trimNullableString($employee->designation ?? null) ?? '',
                ];

                if (!array_key_exists($controlNo, $byRaw)) {
                    $byRaw[$controlNo] = $entry;
                }

                $normalizedKey = $this->normalizeControlNoKey($controlNo);
                if ($normalizedKey !== '' && !array_key_exists($normalizedKey, $byKey)) {
                    $byKey[$normalizedKey] = $entry;
                }
            }

            return [
                'by_raw' => $byRaw,
                'by_key' => $byKey,
            ];
        });
    }

    private function resolveApplicationEmployeeProfile(LeaveApplication $application, array $directory): array
    {
        return $this->resolveEmployeeProfile(
            $this->trimNullableString($application->employee_control_no),
            $this->trimNullableString($application->employee_name)
                ?? $this->trimNullableString($application->applicantAdmin?->full_name),
            $this->trimNullableString($application->applicantAdmin?->department?->name),
            $directory
        );
    }

    private function resolveEmployeeProfile(
        ?string $controlNo,
        ?string $fallbackName,
        ?string $fallbackOffice,
        array $directory
    ): array {
        $rawControlNo = trim((string) ($controlNo ?? ''));
        $normalizedKey = $this->normalizeControlNoKey($rawControlNo);
        $employee = $directory['by_raw'][$rawControlNo]
            ?? ($normalizedKey !== '' ? ($directory['by_key'][$normalizedKey] ?? null) : null);

        return [
            'control_no' => $employee['control_no'] ?? ($rawControlNo !== '' ? $rawControlNo : ''),
            'name' => $this->trimNullableString($employee['name'] ?? null)
                ?? $this->trimNullableString($fallbackName)
                ?? 'Unknown',
            'office' => $this->trimNullableString($employee['office'] ?? null)
                ?? $this->trimNullableString($fallbackOffice)
                ?? '',
            'status' => $this->trimNullableString($employee['status'] ?? null) ?? '',
            'designation' => $this->trimNullableString($employee['designation'] ?? null) ?? '',
        ];
    }

    private function emptyLeaveBalanceAggregate(string $controlNo, string $fallbackName): array
    {
        return [
            'control_no' => $controlNo,
            'fallback_name' => trim($fallbackName),
            'balanceVl' => 0.0,
            'balanceSl' => 0.0,
            'balanceFl' => 0.0,
            'balanceMcCo' => 0.0,
            'balanceWlp' => 0.0,
            'balanceOthers' => 0.0,
            'daysVlFl' => 0.0,
            'daysSl' => 0.0,
            'daysMcCo' => 0.0,
            'daysWlp' => 0.0,
            'daysOthers' => 0.0,
        ];
    }

    private function resolveApplicationPayBreakdown(LeaveApplication $application): array
    {
        $totalDays = round((float) ($application->total_days ?? 0), 2);
        $deductibleDays = $application->deductible_days !== null
            ? round((float) $application->deductible_days, 2)
            : $totalDays;

        if ((bool) $application->is_monetization) {
            return [$deductibleDays, 0.0];
        }

        $payMode = strtoupper(trim((string) ($application->pay_mode ?? LeaveApplication::PAY_MODE_WITH_PAY)));
        if (!in_array($payMode, [LeaveApplication::PAY_MODE_WITH_PAY, LeaveApplication::PAY_MODE_WITHOUT_PAY], true)) {
            $payMode = LeaveApplication::PAY_MODE_WITH_PAY;
        }

        $withPayDays = $payMode === LeaveApplication::PAY_MODE_WITHOUT_PAY ? 0.0 : $deductibleDays;
        if ($withPayDays > $totalDays) {
            $withPayDays = $totalDays;
        }

        $withPayDays = round(max($withPayDays, 0.0), 2);
        $withoutPayDays = round(max($totalDays - $withPayDays, 0.0), 2);

        return [$withPayDays, $withoutPayDays];
    }

    private function resolveApplicationReferenceDate(LeaveApplication $application): ?Carbon
    {
        $dateKeys = $this->resolveApplicationDateKeys($application);
        $dateFromKeys = $this->resolveReferenceDateFromDateKeys($dateKeys);
        if ($dateFromKeys) {
            return $dateFromKeys;
        }

        return $this->asCarbonDate($application->start_date)
            ?? $this->asCarbonDate($application->created_at);
    }

    private function resolveApplicationWopDateKeys(LeaveApplication $application): array
    {
        $selectedDatePayStatus = is_array($application->selected_date_pay_status)
            ? $application->selected_date_pay_status
            : [];

        $wopDates = [];
        foreach ($selectedDatePayStatus as $dateKey => $status) {
            if (strtoupper(trim((string) $status)) !== LeaveApplication::PAY_MODE_WITHOUT_PAY) {
                continue;
            }

            $normalizedKey = $this->normalizeDateKey($dateKey);
            if ($normalizedKey !== null) {
                $wopDates[] = $normalizedKey;
            }
        }

        $wopDates = $this->normalizeDateKeys($wopDates);
        if ($wopDates !== []) {
            return $wopDates;
        }

        [, $withoutPayDays] = $this->resolveApplicationPayBreakdown($application);
        return $withoutPayDays > 0 ? $this->resolveApplicationDateKeys($application) : [];
    }

    private function resolveApplicationDateKeys(LeaveApplication $application): array
    {
        return $this->normalizeDateKeys(LeaveApplication::resolveDateSet(
            $application->start_date?->toDateString(),
            $application->end_date?->toDateString(),
            is_array($application->selected_dates) ? $application->selected_dates : null,
            $application->total_days
        ));
    }

    private function resolveReportableApplicationDays(LeaveApplication $application): float
    {
        if ((bool) $application->is_monetization) {
            return round((float) ($application->deductible_days ?? $application->total_days ?? 0), 2);
        }

        $selectedDates = $this->resolveApplicationDateKeys($application);
        $recalledDates = $this->normalizeDateKeys(
            is_array($application->recall_selected_dates) ? $application->recall_selected_dates : []
        );

        if (
            $application->status === LeaveApplication::STATUS_RECALLED
            && $selectedDates !== []
            && $recalledDates !== []
        ) {
            return round((float) count(array_values(array_diff($selectedDates, $recalledDates))), 2);
        }

        return round((float) ($application->total_days ?? 0), 2);
    }

    private function resolveOfficeLeaveAvailmentDays(LeaveApplication $application): float
    {
        return round((float) ($application->total_days ?? 0), 2);
    }

    private function formatApplicationInclusiveDates(LeaveApplication $application): string
    {
        $dateKeys = $this->resolveApplicationDateKeys($application);
        if ($dateKeys !== []) {
            return $this->formatDateSummaryFromDateKeys($dateKeys);
        }

        $startDate = $this->asCarbonDate($application->start_date);
        $endDate = $this->asCarbonDate($application->end_date);
        if ($startDate && $endDate) {
            if ($startDate->equalTo($endDate)) {
                return $this->formatDateForDisplay($startDate);
            }

            return $this->formatDateForDisplay($startDate) . ' - ' . $this->formatDateForDisplay($endDate);
        }

        return '';
    }

    private function finalizeRows(array $rows): array
    {
        return collect($rows)
            ->sortBy(fn (array $row): string => $row['_sort_key'] ?? '')
            ->values()
            ->map(function (array $row, int $index): array {
                unset($row['_sort_key']);
                $row['no'] = $index + 1;
                return $row;
            })
            ->all();
    }

    private function composeSortKey(array $parts): string
    {
        return implode('|', array_map(
            fn (mixed $value): string => strtoupper(trim((string) $value)),
            $parts
        ));
    }

    private function classifyLeaveBalanceType(?string $leaveTypeName): string
    {
        $normalized = strtoupper(trim((string) ($leaveTypeName ?? '')));

        return match (true) {
            $normalized === 'VACATION LEAVE' => 'vacation',
            $normalized === 'SICK LEAVE' => 'sick',
            $normalized === 'MANDATORY / FORCED LEAVE' => 'forced',
            in_array($normalized, ['MCO6 LEAVE', 'MC06 LEAVE', 'CTO LEAVE'], true) => 'mc_co',
            in_array($normalized, ['SPECIAL PRIVILEGE LEAVE', 'WELLNESS LEAVE', 'SOLO PARENT LEAVE'], true) => 'wlp',
            default => 'others',
        };
    }

    private function classifyLeaveAvailmentType(?string $leaveTypeName): string
    {
        $normalized = strtoupper(trim((string) ($leaveTypeName ?? '')));

        return match (true) {
            in_array($normalized, ['VACATION LEAVE', 'MANDATORY / FORCED LEAVE'], true) => 'vl_fl',
            $normalized === 'SICK LEAVE' => 'sl',
            in_array($normalized, ['MCO6 LEAVE', 'MC06 LEAVE', 'CTO LEAVE'], true) => 'mc_co',
            in_array($normalized, ['SPECIAL PRIVILEGE LEAVE', 'WELLNESS LEAVE', 'SOLO PARENT LEAVE'], true) => 'wlp',
            default => 'others',
        };
    }

    private function classifyOfficeLeaveAvailmentType(?string $leaveTypeName): ?string
    {
        $normalized = strtoupper(trim((string) ($leaveTypeName ?? '')));

        if (in_array($normalized, ['MCO6 LEAVE', 'MC06 LEAVE'], true)) {
            return 'mc_co';
        }

        return match (true) {
            $normalized === 'VACATION LEAVE' => 'vl_fl',
            $normalized === 'SICK LEAVE' => 'sl',
            $normalized === 'WELLNESS LEAVE' => 'wlp',
            default => 'others',
        };
    }

    private function buildEmployeeNameFromSnapshot(?object $employee): ?string
    {
        if (!$employee) {
            return null;
        }

        $parts = [
            $this->trimNullableString($employee->firstname ?? null),
            $this->trimNullableString($employee->middlename ?? null),
            $this->trimNullableString($employee->surname ?? null),
        ];

        $name = trim(implode(' ', array_filter($parts, static fn (?string $part): bool => $part !== null && $part !== '')));
        return $name !== '' ? $name : null;
    }

    private function formatEmploymentStatus(mixed $status): string
    {
        return LeaveType::formatEmploymentStatusLabel($status)
            ?? $this->trimNullableString($status)
            ?? '';
    }

    private function trimNullableString(mixed $value): ?string
    {
        $trimmed = trim((string) ($value ?? ''));
        return $trimmed !== '' ? $trimmed : null;
    }

    private function normalizeControlNoKey(mixed $controlNo): string
    {
        $normalized = ltrim(trim((string) ($controlNo ?? '')), '0');
        if ($normalized === '') {
            $normalized = trim((string) ($controlNo ?? '')) === '' ? '' : '0';
        }

        return $normalized;
    }

    private function normalizeDateKey(mixed $value): ?string
    {
        try {
            $date = Carbon::parse((string) $value)->toDateString();
        } catch (\Throwable) {
            return null;
        }

        return $date !== '' ? $date : null;
    }

    private function normalizeDateKeys(array $values): array
    {
        $normalized = [];

        foreach ($values as $value) {
            $dateKey = $this->normalizeDateKey($value);
            if ($dateKey === null) {
                continue;
            }

            $normalized[$dateKey] = true;
        }

        $dateKeys = array_keys($normalized);
        sort($dateKeys);

        return $dateKeys;
    }

    private function resolveReferenceDateFromDateKeys(array $dateKeys): ?Carbon
    {
        if ($dateKeys === []) {
            return null;
        }

        return Carbon::parse($dateKeys[0])->startOfDay();
    }

    private function formatDateSummaryFromDateKeys(array $dateKeys): string
    {
        $dateKeys = $this->normalizeDateKeys($dateKeys);
        if ($dateKeys === []) {
            return '';
        }

        $segments = [];
        $segmentStart = $dateKeys[0];
        $previous = $dateKeys[0];

        for ($index = 1, $count = count($dateKeys); $index < $count; $index++) {
            $current = $dateKeys[$index];
            $expectedNext = Carbon::parse($previous)->addDay()->toDateString();
            if ($current === $expectedNext) {
                $previous = $current;
                continue;
            }

            $segments[] = [$segmentStart, $previous];
            $segmentStart = $current;
            $previous = $current;
        }

        $segments[] = [$segmentStart, $previous];

        return implode(', ', array_map(function (array $segment): string {
            [$start, $end] = $segment;
            if ($start === $end) {
                return $this->formatDateForDisplay(Carbon::parse($start));
            }

            return $this->formatDateForDisplay(Carbon::parse($start))
                . ' - '
                . $this->formatDateForDisplay(Carbon::parse($end));
        }, $segments));
    }

    private function resolveMonthYear(?Carbon $date): array
    {
        $resolvedDate = $date ?? Carbon::today();

        return [
            'month' => (int) $resolvedDate->month,
            'year' => (int) $resolvedDate->year,
        ];
    }

    private function formatDateForDisplay(?Carbon $date): string
    {
        return $date ? $date->format('M d, Y') : '';
    }

    private function formatSortableDate(?Carbon $date): string
    {
        return $date ? $date->format('Ymd') : '00000000';
    }

    private function formatMonthYearList(array $dates): string
    {
        $labels = [];

        foreach ($dates as $date) {
            $resolvedDate = $this->asCarbonDate($date);
            if (!$resolvedDate) {
                continue;
            }

            $labels[$resolvedDate->format('M Y')] = true;
        }

        return implode(', ', array_keys($labels));
    }

    private function asCarbonDate(mixed $value): ?Carbon
    {
        if ($value instanceof Carbon) {
            return $value->copy();
        }

        if ($value === null || $value === '') {
            return null;
        }

        try {
            return Carbon::parse((string) $value);
        } catch (\Throwable) {
            return null;
        }
    }

    private function resolveCtoLeaveTypeId(): ?int
    {
        static $resolved = false;
        static $cachedId = null;

        if ($resolved) {
            return $cachedId;
        }

        $value = LeaveType::query()
            ->whereRaw('LOWER(LTRIM(RTRIM(name))) = ?', ['cto leave'])
            ->value('id');

        $cachedId = $value !== null ? (int) $value : null;
        $resolved = true;

        return $cachedId;
    }

    private function minutesToHours(int $minutes): float
    {
        return $minutes > 0 ? round($minutes / 60, 2) : 0.0;
    }
}
