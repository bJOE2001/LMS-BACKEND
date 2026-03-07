<?php

namespace App\Http\Controllers;

use App\Models\AdminLeaveBalance;
use App\Models\DepartmentAdmin;
use App\Models\Employee;
use App\Models\LeaveApplication;
use App\Models\LeaveBalance;
use App\Models\LeaveType;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Admin Dashboard — department-level leave application statistics.
 * LOCAL LMS_DB only.
 */
class AdminDashboardController extends Controller
{
    /**
     * Dashboard data: pending count, approved today, total approved, all department applications.
     */
    public function index(Request $request): JsonResponse
    {
        $admin = $request->user();
        if (!$admin instanceof DepartmentAdmin) {
            return response()->json(['message' => 'Only department admins can access this endpoint.'], 403);
        }

        $applications = LeaveApplication::with(['leaveType', 'employee', 'applicantAdmin'])
            ->where(function ($query) use ($admin) {
                // Include employee leaves for this department
                $query->whereHas('employee', fn($q) => $q->where('department_id', $admin->department_id))
                    // OR admin self-apply leaves for this department
                    ->orWhereHas('applicantAdmin', fn($q) => $q->where('department_id', $admin->department_id));
            })
            ->orderByDesc('created_at')
            ->get();

        $pending = $applications->where('status', LeaveApplication::STATUS_PENDING_ADMIN)->count();

        $approvedToday = $applications->filter(function ($app) {
            return $app->status === LeaveApplication::STATUS_PENDING_HR
                || $app->status === LeaveApplication::STATUS_APPROVED
                ? ($app->admin_approved_at && $app->admin_approved_at->isToday())
                : false;
        })->count();

        $totalApproved = $applications->whereIn('status', [
            LeaveApplication::STATUS_PENDING_HR,
            LeaveApplication::STATUS_APPROVED,
        ])->count();

        $formatted = $applications->map(fn($app) => $this->formatApplication($app));

        return response()->json([
            'pending_count' => $pending,
            'approved_today' => $approvedToday,
            'total_approved' => $totalApproved,
            'total_count' => $applications->count(),
            'applications' => $formatted,
        ]);
    }

    // ─── Admin's own leave credits ────────────────────────────────────

    /**
     * Return the admin's own leave summary (accrued, resettable, event-based).
     */
    public function leaveCredits(Request $request): JsonResponse
    {
        $admin = $request->user();
        if (!$admin instanceof DepartmentAdmin) {
            return response()->json(['message' => 'Only department admins can access this endpoint.'], 403);
        }

        $balances = AdminLeaveBalance::where('admin_id', $admin->id)
            ->where('year', now()->year)
            ->with('leaveType')
            ->get()
            ->keyBy('leave_type_id');

        // ACCRUED (Vacation Leave, Sick Leave)
        $accruedTypes = LeaveType::accrued()->orderBy('name')->get();
        $accrued = [];
        foreach ($accruedTypes as $type) {
            $bal = $balances->get($type->id);
            $accrued[] = [
                'id' => $type->id,
                'name' => $type->name,
                'balance' => $bal ? (float) $bal->balance : 0,
                'accrual_rate' => (float) $type->accrual_rate,
                'is_credit_based' => $type->is_credit_based,
            ];
        }

        // RESETTABLE
        $resettableTypes = LeaveType::resettable()->orderBy('name')->get();
        $resettable = $resettableTypes->map(function (LeaveType $type) use ($balances) {
            $bal = $balances->get($type->id);
            return [
                'id' => $type->id,
                'name' => $type->name,
                'balance' => $bal ? (float) $bal->balance : 0,
                'max_days' => $type->max_days,
                'description' => $type->description,
            ];
        })->values();

        // EVENT-BASED
        $eventTypes = LeaveType::eventBased()->orderBy('name')->get();
        $eventBased = $eventTypes->map(fn(LeaveType $type) => [
            'id' => $type->id,
            'name' => $type->name,
            'max_days' => $type->max_days,
            'requires_documents' => $type->requires_documents,
            'description' => $type->description,
        ])->values();

        return response()->json([
            'leave_initialized' => (bool) $admin->leave_initialized,
            'accrued' => $accrued,
            'resettable' => $resettable,
            'event_based' => $eventBased,
        ]);
    }

    /**
     * Get leave types available for admin's own balance initialization.
     */
    public function initializableTypes(Request $request): JsonResponse
    {
        $admin = $request->user();
        if (!$admin instanceof DepartmentAdmin) {
            return response()->json(['message' => 'Only department admins can access this endpoint.'], 403);
        }

        if ($admin->leave_initialized) {
            return response()->json([
                'message' => 'Leave balances already initialized.',
                'leave_initialized' => true,
            ]);
        }

        $types = LeaveType::whereIn('category', [
            LeaveType::CATEGORY_ACCRUED,
            LeaveType::CATEGORY_RESETTABLE,
        ])
            ->orderBy('category')
            ->orderBy('name')
            ->get(['id', 'name', 'category', 'accrual_rate', 'max_days', 'description']);

        return response()->json([
            'leave_initialized' => false,
            'leave_types' => $types,
        ]);
    }

    /**
     * One-time initialization of the admin's own leave balances.
     */
    public function initializeBalance(Request $request): JsonResponse
    {
        $admin = $request->user();
        if (!$admin instanceof DepartmentAdmin) {
            return response()->json(['message' => 'Only department admins can access this endpoint.'], 403);
        }

        if ($admin->leave_initialized) {
            return response()->json(['message' => 'Leave balances already initialized.'], 422);
        }

        $allowedTypeIds = LeaveType::whereIn('category', [
            LeaveType::CATEGORY_ACCRUED,
            LeaveType::CATEGORY_RESETTABLE,
        ])->pluck('id')->toArray();

        $request->validate(['balances' => ['required', 'array', 'min:1']]);

        $balances = $request->input('balances');
        $errors = [];

        foreach ($balances as $typeId => $value) {
            if (!in_array((int) $typeId, $allowedTypeIds, true)) {
                $errors["balances.{$typeId}"] = ["Invalid leave type ID: {$typeId}"];
                continue;
            }
            if (!is_numeric($value) || $value < 0) {
                $errors["balances.{$typeId}"] = ['Balance must be a non-negative number.'];
                continue;
            }
            $leaveType = LeaveType::find((int) $typeId);
            if ($leaveType && $leaveType->max_days !== null && (float) $value > (float) $leaveType->max_days) {
                $errors["balances.{$typeId}"] = ["Balance cannot exceed {$leaveType->max_days} for {$leaveType->name}."];
            }
        }

        foreach ($allowedTypeIds as $id) {
            if (!array_key_exists((string) $id, $balances) && !array_key_exists($id, $balances)) {
                $errors["balances.{$id}"] = ['This leave type balance is required.'];
            }
        }

        if (!empty($errors)) {
            return response()->json(['message' => 'Validation failed.', 'errors' => $errors], 422);
        }

        DB::transaction(function () use ($admin, $balances) {
            $now = now();
            foreach ($balances as $typeId => $value) {
                AdminLeaveBalance::create([
                    'admin_id' => $admin->id,
                    'leave_type_id' => (int) $typeId,
                    'balance' => (float) $value,
                    'initialized_at' => $now,
                    'year' => $now->year,
                ]);
            }
            $admin->update(['leave_initialized' => true]);
        });

        return response()->json(['message' => 'Leave balances initialized successfully.']);
    }

    /**
     * Store a leave application for the admin themselves.
     */
    public function storeSelfLeave(Request $request): JsonResponse
    {
        $admin = $request->user();
        if (!$admin instanceof DepartmentAdmin) {
            return response()->json(['message' => 'Only department admins can access this endpoint.'], 403);
        }

        if (!$admin->leave_initialized) {
            return response()->json(['message' => 'Please initialize your leave balances first.'], 422);
        }

        $request->validate([
            'leave_type_id' => 'required|exists:leave_types,id',
            'start_date' => 'required|date',
            'end_date' => 'required|date|after_or_equal:start_date',
            'total_days' => 'required|numeric|min:0.5',
            'reason' => 'required|string',
        ]);

        $leaveType = LeaveType::find($request->leave_type_id);

        // Check balance if credit-based
        if ($leaveType->is_credit_based) {
            $balance = AdminLeaveBalance::where('admin_id', $admin->id)
                ->where('leave_type_id', $leaveType->id)
                ->where('year', now()->year)
                ->first();

            if (!$balance || $balance->balance < $request->total_days) {
                return response()->json(['message' => 'Insufficient leave balance.'], 422);
            }
        }

        // Create the application
        $application = LeaveApplication::create([
            'applicant_admin_id' => $admin->id,
            'employee_id' => null, // Not an employee
            'leave_type_id' => $leaveType->id,
            'start_date' => $request->start_date,
            'end_date' => $request->end_date,
            'total_days' => $request->total_days,
            'reason' => $request->reason,
            'status' => LeaveApplication::STATUS_PENDING_HR, // Admins go straight to HR review
            'admin_id' => $admin->id, // Self-approved at department level
            'admin_approved_at' => now(),
        ]);

        return response()->json([
            'message' => 'Leave application submitted successfully.',
            'application' => $this->formatApplication($application),
        ]);
    }

    // ─── Helpers ──────────────────────────────────────────────────────

    private function formatApplication(LeaveApplication $app): array
    {
        $statusMap = [
            LeaveApplication::STATUS_PENDING_ADMIN => 'Pending Admin',
            LeaveApplication::STATUS_PENDING_HR => 'Pending HR',
            LeaveApplication::STATUS_APPROVED => 'Approved',
            LeaveApplication::STATUS_REJECTED => 'Rejected',
        ];

        // Determine employee name & office (could be admin self-apply)
        $employeeName = $app->employee ? $app->employee->full_name : ($app->applicantAdmin ? $app->applicantAdmin->full_name : 'Unknown');
        $office = $app->employee?->department?->name ?? ($app->applicantAdmin?->department?->name ?? '');

        return [
            'id' => $app->id,
            'employee_id' => $app->employee_id,
            'employeeName' => $employeeName,
            'employeeId' => $app->employee_id,
            'office' => $office,
            'leaveType' => $app->leaveType?->name ?? 'Unknown',
            'startDate' => $app->start_date ? \Carbon\Carbon::parse($app->start_date)->toDateString() : '',
            'endDate' => $app->end_date ? \Carbon\Carbon::parse($app->end_date)->toDateString() : '',
            'days' => (float) $app->total_days,
            'reason' => $app->reason,
            'status' => $statusMap[$app->status] ?? $app->status,
            'rawStatus' => $app->status,
            'dateFiled' => $app->created_at ? $app->created_at->toDateString() : '',
            'remarks' => $app->remarks,
            'leaveBalance' => $this->getBalanceForApp($app),
        ];
    }

    private function getBalanceForApp(LeaveApplication $app): ?float
    {
        if ($app->employee_id) {
            $balance = LeaveBalance::where('employee_id', $app->employee_id)
                ->where('leave_type_id', $app->leave_type_id)
                ->first();
            return $balance ? (float) $balance->balance : null;
        }

        if ($app->applicant_admin_id) {
            $balance = AdminLeaveBalance::where('admin_id', $app->applicant_admin_id)
                ->where('leave_type_id', $app->leave_type_id)
                ->where('year', now()->year)
                ->first();
            return $balance ? (float) $balance->balance : null;
        }

        return null;
    }
}
