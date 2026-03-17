<?php

namespace App\Http\Controllers;

use App\Models\DepartmentAdmin;
use App\Models\Employee;
use App\Models\HRAccount;
use App\Models\LeaveApplication;
use App\Models\LeaveBalance;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * HR Dashboard — organisation-wide leave application statistics.
 * LOCAL LMS_DB only.
 */
class HRDashboardController extends Controller
{
    /**
     * Dashboard data: total, pending HR, approved, on-leave-today counts + all applications.
     */
    public function index(Request $request): JsonResponse
    {
        $hr = $request->user();
        if (!$hr instanceof HRAccount) {
            return response()->json(['message' => 'Only HR accounts can access this endpoint.'], 403);
        }

        $applications = LeaveApplication::with(['leaveType', 'applicantAdmin.department'])
            ->orderByDesc('created_at')
            ->get();

        $pendingHR = $applications->where('status', LeaveApplication::STATUS_PENDING_HR)->count();
        $totalApproved = $applications->where('status', LeaveApplication::STATUS_APPROVED)->count();
        $totalRejected = $applications->where('status', LeaveApplication::STATUS_REJECTED)->count();

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

        $formatted = $applications->map(fn($app) => $this->formatApplication($app));

        return response()->json([
            'total_count' => $applications->count(),
            'pending_count' => $pendingHR,
            'approved_count' => $totalApproved,
            'rejected_count' => $totalRejected,
            'on_leave_today' => $onLeaveToday,
            'applications' => $formatted,
        ]);
    }

    private function formatApplication(LeaveApplication $app): array
    {
        $statusMap = [
            LeaveApplication::STATUS_PENDING_ADMIN => 'Pending Admin',
            LeaveApplication::STATUS_PENDING_HR => 'Pending HR',
            LeaveApplication::STATUS_APPROVED => 'Approved',
            LeaveApplication::STATUS_REJECTED => 'Rejected',
        ];

        // Determine applicant name & office
        $employeeName = $app->employee
            ? trim(($app->employee->firstname ?? '') . ' ' . ($app->employee->surname ?? ''))
            : null;
        $applicantName = $employeeName ?: ($app->applicantAdmin ? $app->applicantAdmin->full_name : 'Unknown');
        $office = $app->employee?->office ?? ($app->applicantAdmin?->department?->name ?? '');
        $durationDays = (float) $app->total_days;

        return [
            'id' => $app->id,
            'employee_id' => $app->erms_control_no,
            'employeeName' => $applicantName,
            'office' => $office,
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
            'dateFiled' => $app->created_at ? $app->created_at->toDateString() : '',
            'selected_dates' => $app->resolvedSelectedDates(),
            'is_monetization' => (bool) $app->is_monetization,
            'equivalent_amount' => $app->equivalent_amount ? (float) $app->equivalent_amount : null,
            'leaveBalance' => $this->getBalanceForApp($app),
        ];
    }

    private function getBalanceForApp(LeaveApplication $app): ?float
    {
        if ($app->erms_control_no) {
            $employeeControlNo = trim((string) $app->erms_control_no);
            $candidateEmployeeIds = $this->controlNoCandidates($employeeControlNo);
            if ($candidateEmployeeIds === []) {
                return null;
            }

            $balance = LeaveBalance::query()
                ->where('leave_type_id', $app->leave_type_id)
                ->whereIn('employee_id', $candidateEmployeeIds)
                ->first();
            return $balance ? (float) $balance->balance : null;
        }

        if ($app->applicant_admin_id) {
            $adminControlNo = $this->resolveAdminEmployeeControlNo((int) $app->applicant_admin_id);
            if ($adminControlNo === null) {
                return null;
            }

            $candidateEmployeeIds = $this->controlNoCandidates($adminControlNo);
            if ($candidateEmployeeIds === []) {
                return null;
            }

            $balance = LeaveBalance::query()
                ->where('leave_type_id', $app->leave_type_id)
                ->whereIn('employee_id', $candidateEmployeeIds)
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

        $employee = Employee::findByControlNo($rawControlNo);
        return trim((string) ($employee?->control_no ?? $rawControlNo));
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

        $employee = Employee::findByControlNo($rawControlNo);
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
            $query->where(function ($q) use ($dept) {
                $q->whereIn('erms_control_no', function ($sq) use ($dept) {
                    $sq->select('control_no')->from('tblEmployees')->where('office', $dept);
                })
                    ->orWhereHas('applicantAdmin.department', fn($sq) => $sq->where('name', $dept));
            });
        }

        $applications = $query->orderBy('start_date')->get();

        $formatted = $applications->map(fn($app) => [
            'id' => $app->id,
            'employeeName' => $app->employee
                ? trim(($app->employee->firstname ?? '') . ' ' . ($app->employee->surname ?? ''))
                : ($app->applicantAdmin ? $app->applicantAdmin->full_name : 'Unknown'),
            'employee_id' => $app->erms_control_no,
            'office' => $app->employee?->office ?? ($app->applicantAdmin?->department?->name ?? ''),
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
        ]);

        return response()->json(['leaves' => $formatted]);
    }
}
