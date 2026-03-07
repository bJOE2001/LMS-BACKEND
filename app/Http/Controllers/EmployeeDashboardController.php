<?php

namespace App\Http\Controllers;

use App\Models\Employee;
use App\Models\EmployeeAccount;
use App\Models\LeaveApplication;
use App\Models\LeaveBalance;
use App\Models\LeaveType;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Employee dashboard — leave summary (4 cards), application counts, recent applications.
 * LOCAL LMS_DB only.
 */
class EmployeeDashboardController extends Controller
{
    /**
     * Dashboard data: leave summary, application summary, recent applications.
     */
    public function index(Request $request): JsonResponse
    {
        $employee = $this->resolveEmployee($request);
        if ($employee instanceof JsonResponse) {
            return $employee;
        }

        $applications = LeaveApplication::with('leaveType')
            ->where('employee_id', $employee->id)
            ->orderByDesc('created_at')
            ->get();

        $pending = $applications->whereIn('status', [
            LeaveApplication::STATUS_PENDING_ADMIN,
            LeaveApplication::STATUS_PENDING_HR,
        ])->count();
        $approved = $applications->where('status', LeaveApplication::STATUS_APPROVED)->count();
        $rejected = $applications->where('status', LeaveApplication::STATUS_REJECTED)->count();

        $recent = $applications->take(10)->map(fn($app) => $this->formatApplication($app));

        return response()->json([
            'leave_initialized' => $employee->leave_initialized,
            'application_summary' => [
                'pending' => $pending,
                'approved' => $approved,
                'rejected' => $rejected,
            ],
            'recent_applications' => $recent,
        ]);
    }

    /**
     * GET /employee/dashboard/leave-summary
     * Structured leave summary: accrued, resettable, event_based.
     */
    public function leaveSummary(Request $request): JsonResponse
    {
        $employee = $this->resolveEmployee($request);
        if ($employee instanceof JsonResponse) {
            return $employee;
        }

        $balances = LeaveBalance::where('employee_id', $employee->id)
            ->with('leaveType')
            ->get()
            ->keyBy('leave_type_id');

        // ─── ACCRUED ─────────────────────────────────────────────────────
        $accruedTypes = LeaveType::accrued()->orderBy('name')->get();
        $accrued = [];
        foreach ($accruedTypes as $type) {
            $bal = $balances->get($type->id);
            $accrued[str_replace([' ', '/'], '_', strtolower($type->name))] = [
                'id' => $type->id,
                'name' => $type->name,
                'balance' => $bal ? (float) $bal->balance : 0,
                'accrual_rate' => (float) $type->accrual_rate,
                'last_accrual_date' => $bal?->last_accrual_date?->format('Y-m-d'),
                'is_credit_based' => $type->is_credit_based,
            ];
        }

        // ─── RESETTABLE ──────────────────────────────────────────────────
        $resettableTypes = LeaveType::resettable()->orderBy('name')->get();
        $resettable = $resettableTypes->map(function (LeaveType $type) use ($balances) {
            $bal = $balances->get($type->id);
            return [
                'id' => $type->id,
                'name' => $type->name,
                'balance' => $bal ? (float) $bal->balance : 0,
                'max_days' => $type->max_days,
                'year' => $bal?->year ?? now()->year,
                'description' => $type->description,
            ];
        })->values();

        // ─── EVENT-BASED ─────────────────────────────────────────────────
        $eventTypes = LeaveType::eventBased()->orderBy('name')->get();
        $eventBased = $eventTypes->map(function (LeaveType $type) {
            return [
                'id' => $type->id,
                'name' => $type->name,
                'max_days' => $type->max_days,
                'requires_documents' => $type->requires_documents,
                'description' => $type->description,
            ];
        })->values();

        return response()->json([
            'leave_initialized' => $employee->leave_initialized,
            'accrued' => $accrued,
            'resettable' => $resettable,
            'event_based' => $eventBased,
        ]);
    }

    // ─── Helpers ─────────────────────────────────────────────────────────

    private function resolveEmployee(Request $request): Employee|JsonResponse
    {
        $account = $request->user();
        if (!$account instanceof EmployeeAccount) {
            return response()->json(['message' => 'Only employee accounts can access the dashboard.'], 403);
        }

        $employee = Employee::find($account->employee_id);
        if (!$employee) {
            return response()->json(['message' => 'Employee record not found.'], 404);
        }

        return $employee;
    }

    private function formatApplication(LeaveApplication $app): array
    {
        $statusMap = [
            LeaveApplication::STATUS_PENDING_ADMIN => 'Pending Admin',
            LeaveApplication::STATUS_PENDING_HR => 'Pending HR',
            LeaveApplication::STATUS_APPROVED => 'Approved',
            LeaveApplication::STATUS_REJECTED => 'Rejected',
        ];

        return [
            'id' => $app->id,
            'leaveType' => $app->leaveType?->name ?? 'Unknown',
            'startDate' => $app->start_date->format('Y-m-d'),
            'endDate' => $app->end_date->format('Y-m-d'),
            'days' => (float) $app->total_days,
            'reason' => $app->reason,
            'status' => $statusMap[$app->status] ?? $app->status,
            'rawStatus' => $app->status,
            'dateFiled' => $app->created_at->format('Y-m-d'),
            'remarks' => $app->remarks,
        ];
    }
}
