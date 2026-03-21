<?php

namespace App\Http\Controllers;

use App\Models\Department;
use App\Models\Employee;
use App\Models\HRAccount;
use App\Models\LeaveApplication;
use App\Models\LeaveType;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class HRReportController extends Controller
{
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

        // Calculate average processing time in days for approved applications
        $avgProcessingDays = (clone $approvedApplicationsQuery)
            ->whereNotNull('hr_approved_at')
            ->get(['created_at', 'hr_approved_at'])
            ->avg(static function (LeaveApplication $application): float {
                return (float) $application->created_at?->diffInDays($application->hr_approved_at);
            });

        $activeEmployeesCount = Employee::count();

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
        $departments = Department::query()->orderBy('name')->get(['id', 'name']);

        $stats = $departments->map(function (Department $department) use ($today, $dateFrom, $dateTo): array {
            $employeeControlNumbers = Employee::query()
                ->where('office', $department->name)
                ->pluck('control_no');

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
                LeaveApplication::STATUS_PENDING_HR
            ])->count();

            $approved = $applications->where('status', LeaveApplication::STATUS_APPROVED)->count();

            // Utilization % = (Approved / Employees) * 100 (Simplified)
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

        $query = LeaveApplication::with(['leaveType', 'employee', 'applicantAdmin.department'])
            ->orderByDesc('created_at');

        $this->applyDateRange($query, $dateFrom, $dateTo);

        $applications = $query->get()->map(static function (LeaveApplication $application): array {
            $applicantName = $application->employee
                ? trim(($application->employee->firstname ?? '') . ' ' . ($application->employee->surname ?? ''))
                : ($application->applicantAdmin ? $application->applicantAdmin->full_name : 'Unknown');
            $deptName = $application->employee?->office ?? $application->applicantAdmin?->department?->name ?? 'N/A';

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

    private function ensureHr(Request $request): ?JsonResponse
    {
        if (!$request->user() instanceof HRAccount) {
            return response()->json([
                'message' => 'Only HR accounts can access this resource.',
            ], 403);
        }

        return null;
    }
}
