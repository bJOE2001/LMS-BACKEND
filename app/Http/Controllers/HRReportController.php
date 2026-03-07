<?php

namespace App\Http\Controllers;

use App\Models\Department;
use App\Models\Employee;
use App\Models\HRAccount;
use App\Models\LeaveApplication;
use App\Models\LeaveType;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

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

        $totalApplications = LeaveApplication::count();
        $approvedCount = LeaveApplication::where('status', LeaveApplication::STATUS_APPROVED)->count();
        
        $approvalRate = $totalApplications > 0 
            ? round(($approvedCount / $totalApplications) * 100, 1) 
            : 0;

        // Calculate average processing time in days for approved applications
        $avgProcessingDays = LeaveApplication::where('status', LeaveApplication::STATUS_APPROVED)
            ->whereNotNull('hr_approved_at')
            ->get()
            ->avg(function ($app) {
                return $app->created_at->diffInDays($app->hr_approved_at);
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
        
        $departments = Department::all();
        
        $stats = $departments->map(function ($dept) use ($today) {
            $employeeCount = Employee::where('office', $dept->name)->count();

            $applications = LeaveApplication::where(function ($q) use ($dept) {
                $q->whereIn('erms_control_no', function ($sq) use ($dept) {
                    $sq->select('control_no')->from('tblEmployees')->where('office', $dept->name);
                })->orWhereHas('applicantAdmin', function ($sq) use ($dept) {
                    $sq->where('department_id', $dept->id);
                });
            })->get();

            $onLeaveToday = $applications->filter(function ($app) use ($today) {
                if ($app->status !== LeaveApplication::STATUS_APPROVED) return false;
                $start = Carbon::parse($app->start_date)->toDateString();
                $end = Carbon::parse($app->end_date)->toDateString();
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
                'dept' => $dept->name,
                'total' => $employeeCount,
                'onLeave' => $onLeaveToday,
                'pending' => $pending,
                'approved' => $approved,
                'rate' => $utilization
            ];
        });

        return response()->json($stats);
    }

    /**
     * Get usage statistics by leave type.
     */
    public function getLeaveTypeStats(Request $request): JsonResponse
    {
        if ($response = $this->ensureHr($request)) {
            return $response;
        }

        $stats = LeaveType::withCount(['leaveApplications' => function ($q) {
            $q->where('status', LeaveApplication::STATUS_APPROVED);
        }])->get()->map(fn($type) => [
            'name' => $type->name,
            'count' => $type->leave_applications_count
        ]);

        return response()->json($stats);
    }

    /**
     * Generate report data based on filters.
     */
    public function generateReport(Request $request): JsonResponse
    {
        if ($response = $this->ensureHr($request)) {
            return $response;
        }

        $request->validate([
            'type' => 'required|string',
            'date_from' => 'nullable|date',
            'date_to' => 'nullable|date',
        ]);

        $query = LeaveApplication::with(['leaveType', 'applicantAdmin.department']);

        if ($request->date_from) {
            $query->where('created_at', '>=', $request->date_from);
        }
        if ($request->date_to) {
            $query->where('created_at', '<=', Carbon::parse($request->date_to)->endOfDay());
        }

        $applications = $query->get()->map(function ($app) {
            $applicantName = $app->employee
                ? trim(($app->employee->firstname ?? '') . ' ' . ($app->employee->surname ?? ''))
                : ($app->applicantAdmin ? $app->applicantAdmin->full_name : 'Unknown');
            $deptName = $app->employee?->office ?? $app->applicantAdmin?->department?->name ?? 'N/A';
            
            return [
                'id' => $app->id,
                'applicant' => $applicantName,
                'department' => $deptName,
                'leave_type' => $app->leaveType->name,
                'start_date' => Carbon::parse($app->start_date)->toDateString(),
                'end_date' => Carbon::parse($app->end_date)->toDateString(),
                'days' => (float)$app->total_days,
                'status' => $app->status,
                'date_filed' => $app->created_at->toDateString(),
            ];
        });

        return response()->json($applications);
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
