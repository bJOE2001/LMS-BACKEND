<?php

namespace App\Http\Controllers;

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

        return [
            'id' => $app->id,
            'employee_id' => $app->erms_control_no,
            'employeeName' => $applicantName,
            'office' => $office,
            'leaveType' => $app->leaveType?->name ?? 'Unknown',
            'startDate' => $app->start_date ? \Carbon\Carbon::parse($app->start_date)->toDateString() : null,
            'endDate' => $app->end_date ? \Carbon\Carbon::parse($app->end_date)->toDateString() : null,
            'days' => (float) $app->total_days,
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
            $balance = \App\Models\LeaveBalance::where('employee_id', $app->erms_control_no)
                ->where('leave_type_id', $app->leave_type_id)
                ->first();
            return $balance ? (float) $balance->balance : null;
        }

        if ($app->applicant_admin_id) {
            $balance = \App\Models\AdminLeaveBalance::where('admin_id', $app->applicant_admin_id)
                ->where('leave_type_id', $app->leave_type_id)
                ->where('year', now()->year)
                ->first();
            return $balance ? (float) $balance->balance : null;
        }

        return null;
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
            'dateFiled' => $app->created_at ? $app->created_at->toDateString() : '',
            'status' => 'Approved',
        ]);

        return response()->json(['leaves' => $formatted]);
    }
}
