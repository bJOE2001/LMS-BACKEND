<?php

namespace App\Http\Controllers;

use App\Models\DepartmentAdmin;
use App\Models\Employee;
use App\Models\EmployeeAccount;
use App\Models\HRAccount;
use App\Models\LeaveApplication;
use App\Models\LeaveApplicationLog;
use App\Models\LeaveBalance;
use App\Models\LeaveType;
use App\Models\Notification;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Leave Application Workflow Controller.
 *
 * Flow: Employee submits → Admin approves/rejects → HR approves/rejects.
 * On HR approval, credit-based leave balances are deducted.
 */
class LeaveApplicationController extends Controller
{
    // ─── Employee: List own applications ──────────────────────────────

    public function index(Request $request): JsonResponse
    {
        $account = $request->user();
        if (!$account instanceof EmployeeAccount) {
            return response()->json(['message' => 'Only employee accounts can list their leave applications.'], 403);
        }

        $applications = LeaveApplication::with('leaveType')
            ->where('employee_id', $account->employee_id)
            ->orderByDesc('created_at')
            ->get();

        return response()->json([
            'applications' => $applications->map(fn($app) => $this->formatApplication($app)),
        ]);
    }

    // ─── Employee: Submit new leave application ──────────────────────

    public function store(Request $request): JsonResponse
    {
        $account = $request->user();
        if (!$account instanceof EmployeeAccount) {
            return response()->json(['message' => 'Only employee accounts can submit leave applications.'], 403);
        }

        $employee = Employee::find($account->employee_id);
        if (!$employee) {
            return response()->json(['message' => 'Employee record not found.'], 404);
        }

        $validated = $request->validate([
            'leave_type_id' => ['required', 'integer', 'exists:leave_types,id'],
            'start_date' => ['required', 'date'],
            'end_date' => ['required', 'date', 'after_or_equal:start_date'],
            'total_days' => ['required', 'numeric', 'min:0.5', 'max:365'],
            'reason' => ['nullable', 'string', 'max:2000'],
        ]);

        // Check balance for credit-based leave types
        $leaveType = LeaveType::find($validated['leave_type_id']);
        if ($leaveType && $leaveType->is_credit_based) {
            $balance = LeaveBalance::where('employee_id', $employee->id)
                ->where('leave_type_id', $validated['leave_type_id'])
                ->first();

            $currentBalance = $balance ? (float) $balance->balance : 0;

            if ($currentBalance < (float) $validated['total_days']) {
                $fmtAvail = self::formatDays($currentBalance);
                $fmtReq = self::formatDays($validated['total_days']);
                return response()->json([
                    'message' => "Insufficient leave balance. You have {$fmtAvail} available but requested {$fmtReq}.",
                    'errors' => [
                        'total_days' => ["Insufficient leave balance. Available: {$fmtAvail}."]
                    ],
                ], 422);
            }
        }

        $app = DB::transaction(function () use ($validated, $employee, $account) {
            $application = LeaveApplication::create([
                'employee_id' => $employee->id,
                'leave_type_id' => $validated['leave_type_id'],
                'start_date' => $validated['start_date'],
                'end_date' => $validated['end_date'],
                'total_days' => $validated['total_days'],
                'reason' => $validated['reason'] ?? null,
                'status' => LeaveApplication::STATUS_PENDING_ADMIN,
            ]);

            LeaveApplicationLog::create([
                'leave_application_id' => $application->id,
                'action' => LeaveApplicationLog::ACTION_SUBMITTED,
                'performed_by_type' => LeaveApplicationLog::PERFORMER_EMPLOYEE,
                'performed_by_id' => $account->id,
                'created_at' => now(),
            ]);

            return $application;
        });

        // Notify department admins about the new application
        $app->load('leaveType');
        $admins = DepartmentAdmin::where('department_id', $employee->department_id)->get();
        foreach ($admins as $deptAdmin) {
            Notification::send(
                $deptAdmin,
                Notification::TYPE_LEAVE_REQUEST,
                'New Leave Application',
                "{$employee->full_name} submitted a {$app->leaveType->name} leave request (" . self::formatDays($app->total_days) . ").",
                $app->id
            );
        }

        return response()->json([
            'message' => 'Leave application submitted successfully.',
            'application' => $this->formatApplication($app->fresh('leaveType')),
        ], 201);
    }

    // ─── Employee: View own single application ───────────────────────

    public function show(Request $request, LeaveApplication $leaveApplication): JsonResponse
    {
        $account = $request->user();
        if (!$account instanceof EmployeeAccount) {
            return response()->json(['message' => 'Only employee accounts can view leave applications.'], 403);
        }

        if ((int) $leaveApplication->employee_id !== (int) $account->employee_id) {
            return response()->json(['message' => 'Leave application not found.'], 404);
        }

        $leaveApplication->load(['leaveType', 'logs']);

        return response()->json([
            'application' => $this->formatApplication($leaveApplication),
        ]);
    }

    // ═══════════════════════════════════════════════════════════════════
    //  ADMIN ACTIONS
    // ═══════════════════════════════════════════════════════════════════

    /**
     * List PENDING_ADMIN applications for the admin's department.
     */
    public function adminIndex(Request $request): JsonResponse
    {
        $admin = $request->user();
        if (!$admin instanceof DepartmentAdmin) {
            return response()->json(['message' => 'Only department admins can access this endpoint.'], 403);
        }

        $applications = LeaveApplication::with(['leaveType', 'employee'])
            ->where('status', LeaveApplication::STATUS_PENDING_ADMIN)
            ->whereHas('employee', fn($q) => $q->where('department_id', $admin->department_id))
            ->orderByDesc('created_at')
            ->get();

        return response()->json([
            'applications' => $applications->map(fn($app) => $this->formatApplication($app)),
        ]);
    }

    /**
     * Admin approves → status becomes PENDING_HR.
     */
    public function adminApprove(Request $request, int $id): JsonResponse
    {
        $admin = $request->user();
        if (!$admin instanceof DepartmentAdmin) {
            return response()->json(['message' => 'Only department admins can approve applications.'], 403);
        }

        $request->validate([
            'remarks' => ['nullable', 'string', 'max:2000'],
        ]);

        $app = LeaveApplication::with('employee')->find($id);
        if (!$app) {
            return response()->json(['message' => 'Leave application not found.'], 404);
        }

        // Security: admin can only act on their own department's applications
        if ((int) $app->employee->department_id !== (int) $admin->department_id) {
            return response()->json(['message' => 'You can only manage applications from your department.'], 403);
        }

        // Prevent double approval
        if ($app->status !== LeaveApplication::STATUS_PENDING_ADMIN) {
            return response()->json(['message' => "Cannot approve: application status is '{$app->status}', expected 'PENDING_ADMIN'."], 422);
        }

        DB::transaction(function () use ($app, $admin, $request) {
            $app->update([
                'status' => LeaveApplication::STATUS_PENDING_HR,
                'admin_id' => $admin->id,
                'admin_approved_at' => now(),
                'remarks' => $request->input('remarks'),
            ]);

            LeaveApplicationLog::create([
                'leave_application_id' => $app->id,
                'action' => LeaveApplicationLog::ACTION_ADMIN_APPROVED,
                'performed_by_type' => LeaveApplicationLog::PERFORMER_ADMIN,
                'performed_by_id' => $admin->id,
                'remarks' => $request->input('remarks'),
                'created_at' => now(),
            ]);
        });

        // Notify the employee that admin approved
        $empAccount = EmployeeAccount::where('employee_id', $app->employee_id)->first();
        if ($empAccount) {
            $app->load('leaveType');
            Notification::send(
                $empAccount,
                Notification::TYPE_LEAVE_PENDING,
                'Leave Approved by Admin',
                "Your {$app->leaveType->name} leave application has been approved by your department admin and forwarded to HR.",
                $app->id
            );
        }

        // Notify all HR accounts about the new pending application
        $hrAccounts = HRAccount::all();
        foreach ($hrAccounts as $hrAccount) {
            $app->load(['leaveType', 'employee']);
            Notification::send(
                $hrAccount,
                Notification::TYPE_LEAVE_REQUEST,
                'Leave Application Pending HR Review',
                "{$app->employee->full_name}'s {$app->leaveType->name} leave (" . self::formatDays($app->total_days) . ") has been approved by admin and awaits your review.",
                $app->id
            );
        }

        return response()->json([
            'message' => 'Application approved by admin. Forwarded to HR.',
            'application' => $this->formatApplication($app->fresh('leaveType')),
        ]);
    }

    /**
     * Admin rejects → status becomes REJECTED.
     */
    public function adminReject(Request $request, int $id): JsonResponse
    {
        $admin = $request->user();
        if (!$admin instanceof DepartmentAdmin) {
            return response()->json(['message' => 'Only department admins can reject applications.'], 403);
        }

        $request->validate([
            'remarks' => ['nullable', 'string', 'max:2000'],
        ]);

        $app = LeaveApplication::with('employee')->find($id);
        if (!$app) {
            return response()->json(['message' => 'Leave application not found.'], 404);
        }

        if ((int) $app->employee->department_id !== (int) $admin->department_id) {
            return response()->json(['message' => 'You can only manage applications from your department.'], 403);
        }

        if ($app->status !== LeaveApplication::STATUS_PENDING_ADMIN) {
            return response()->json(['message' => "Cannot reject: application status is '{$app->status}', expected 'PENDING_ADMIN'."], 422);
        }

        DB::transaction(function () use ($app, $admin, $request) {
            $app->update([
                'status' => LeaveApplication::STATUS_REJECTED,
                'remarks' => $request->input('remarks'),
            ]);

            LeaveApplicationLog::create([
                'leave_application_id' => $app->id,
                'action' => LeaveApplicationLog::ACTION_ADMIN_REJECTED,
                'performed_by_type' => LeaveApplicationLog::PERFORMER_ADMIN,
                'performed_by_id' => $admin->id,
                'remarks' => $request->input('remarks'),
                'created_at' => now(),
            ]);
        });

        // Notify the employee that admin rejected
        $empAccount = EmployeeAccount::where('employee_id', $app->employee_id)->first();
        if ($empAccount) {
            $app->load('leaveType');
            Notification::send(
                $empAccount,
                Notification::TYPE_LEAVE_REJECTED,
                'Leave Application Rejected',
                "Your {$app->leaveType->name} leave application was rejected by your department admin." . ($request->input('remarks') ? " Reason: {$request->input('remarks')}" : ''),
                $app->id
            );
        }

        return response()->json([
            'message' => 'Application rejected by admin.',
            'application' => $this->formatApplication($app->fresh('leaveType')),
        ]);
    }

    // ═══════════════════════════════════════════════════════════════════
    //  HR ACTIONS
    // ═══════════════════════════════════════════════════════════════════

    /**
     * List PENDING_HR applications (all departments).
     */
    public function hrIndex(Request $request): JsonResponse
    {
        $hr = $request->user();
        if (!$hr instanceof HRAccount) {
            return response()->json(['message' => 'Only HR accounts can access this endpoint.'], 403);
        }

        $applications = LeaveApplication::with(['leaveType', 'employee', 'employee.department'])
            ->where('status', LeaveApplication::STATUS_PENDING_HR)
            ->orderByDesc('created_at')
            ->get();

        return response()->json([
            'applications' => $applications->map(fn($app) => $this->formatApplication($app)),
        ]);
    }

    /**
     * HR approves → status becomes APPROVED.
     * If leave type is credit-based, deduct from leave_balances inside a transaction.
     */
    public function hrApprove(Request $request, int $id): JsonResponse
    {
        $hr = $request->user();
        if (!$hr instanceof HRAccount) {
            return response()->json(['message' => 'Only HR accounts can approve applications.'], 403);
        }

        $request->validate([
            'remarks' => ['nullable', 'string', 'max:2000'],
        ]);

        $app = LeaveApplication::with('leaveType')->find($id);
        if (!$app) {
            return response()->json(['message' => 'Leave application not found.'], 404);
        }

        if ($app->status !== LeaveApplication::STATUS_PENDING_HR) {
            return response()->json(['message' => "Cannot approve: application status is '{$app->status}', expected 'PENDING_HR'."], 422);
        }

        // Deduct balance for credit-based leave types
        if ($app->leaveType && $app->leaveType->is_credit_based) {
            if ($app->employee_id) {
                $balance = LeaveBalance::where('employee_id', $app->employee_id)
                    ->where('leave_type_id', $app->leave_type_id)
                    ->first();

                if (!$balance || $balance->balance < $app->total_days) {
                    $currentBalance = $balance ? $balance->balance : 0;
                    return response()->json([
                        'message' => "Insufficient leave balance. Current: {$currentBalance}, Requested: {$app->total_days}.",
                    ], 422);
                }
            } elseif ($app->applicant_admin_id) {
                $balance = \App\Models\AdminLeaveBalance::where('admin_id', $app->applicant_admin_id)
                    ->where('leave_type_id', $app->leave_type_id)
                    ->where('year', now()->year)
                    ->first();

                if (!$balance || $balance->balance < $app->total_days) {
                    $currentBalance = $balance ? $balance->balance : 0;
                    return response()->json([
                        'message' => "Insufficient leave balance. Current: {$currentBalance}, Requested: {$app->total_days}.",
                    ], 422);
                }
            }
        }

        DB::transaction(function () use ($app, $hr, $request) {
            $app->update([
                'status' => LeaveApplication::STATUS_APPROVED,
                'hr_id' => $hr->id,
                'hr_approved_at' => now(),
                'remarks' => $request->input('remarks'),
            ]);

            // Deduct balance
            if ($app->leaveType && $app->leaveType->is_credit_based) {
                if ($app->employee_id) {
                    LeaveBalance::where('employee_id', $app->employee_id)
                        ->where('leave_type_id', $app->leave_type_id)
                        ->decrement('balance', $app->total_days);
                } elseif ($app->applicant_admin_id) {
                    \App\Models\AdminLeaveBalance::where('admin_id', $app->applicant_admin_id)
                        ->where('leave_type_id', $app->leave_type_id)
                        ->where('year', now()->year)
                        ->decrement('balance', $app->total_days);
                }
            }

            LeaveApplicationLog::create([
                'leave_application_id' => $app->id,
                'action' => LeaveApplicationLog::ACTION_HR_APPROVED,
                'performed_by_type' => LeaveApplicationLog::PERFORMER_HR,
                'performed_by_id' => $hr->id,
                'remarks' => $request->input('remarks'),
                'created_at' => now(),
            ]);
        });

        // Notify the applicant
        $msg = "Your {$app->leaveType->name} leave application (" . self::formatDays($app->total_days) . ") has been fully approved!";
        if ($app->employee_id) {
            $empAccount = EmployeeAccount::where('employee_id', $app->employee_id)->first();
            if ($empAccount) {
                Notification::send($empAccount, Notification::TYPE_LEAVE_APPROVED, 'Leave Application Approved', $msg, $app->id);
            }
        } elseif ($app->applicant_admin_id) {
            Notification::send($app->applicantAdmin, Notification::TYPE_LEAVE_APPROVED, 'Leave Application Approved', $msg, $app->id);
        }

        return response()->json([
            'message' => 'Application approved by HR.',
            'application' => $this->formatApplication($app->fresh(['leaveType', 'employee', 'applicantAdmin'])),
        ]);
    }

    /**
     * HR rejects → status becomes REJECTED.
     */
    public function hrReject(Request $request, int $id): JsonResponse
    {
        $hr = $request->user();
        if (!$hr instanceof HRAccount) {
            return response()->json(['message' => 'Only HR accounts can reject applications.'], 403);
        }

        $request->validate([
            'remarks' => ['nullable', 'string', 'max:2000'],
        ]);

        $app = LeaveApplication::find($id);
        if (!$app) {
            return response()->json(['message' => 'Leave application not found.'], 404);
        }

        if ($app->status !== LeaveApplication::STATUS_PENDING_HR) {
            return response()->json(['message' => "Cannot reject: application status is '{$app->status}', expected 'PENDING_HR'."], 422);
        }

        DB::transaction(function () use ($app, $hr, $request) {
            $app->update([
                'status' => LeaveApplication::STATUS_REJECTED,
                'remarks' => $request->input('remarks'),
            ]);

            LeaveApplicationLog::create([
                'leave_application_id' => $app->id,
                'action' => LeaveApplicationLog::ACTION_HR_REJECTED,
                'performed_by_type' => LeaveApplicationLog::PERFORMER_HR,
                'performed_by_id' => $hr->id,
                'remarks' => $request->input('remarks'),
                'created_at' => now(),
            ]);
        });

        // Notify the employee that HR rejected
        $empAccount = EmployeeAccount::where('employee_id', $app->employee_id)->first();
        if ($empAccount) {
            $app->load('leaveType');
            Notification::send(
                $empAccount,
                Notification::TYPE_LEAVE_REJECTED,
                'Leave Application Rejected by HR',
                "Your {$app->leaveType->name} leave application was rejected by HR." . ($request->input('remarks') ? " Reason: {$request->input('remarks')}" : ''),
                $app->id
            );
        }

        return response()->json([
            'message' => 'Application rejected by HR.',
            'application' => $this->formatApplication($app->fresh('leaveType')),
        ]);
    }

    // ─── Admin: List department employees for apply-leave form ────────

    /**
     * Return employees in the admin's department with leave types & balances.
     * Powers the admin "Apply Leave" form dropdowns.
     */
    public function adminEmployees(Request $request): JsonResponse
    {
        $admin = $request->user();
        if (!$admin instanceof DepartmentAdmin) {
            return response()->json(['message' => 'Only department admins can access this endpoint.'], 403);
        }

        $employees = Employee::where('department_id', $admin->department_id)
            ->with('leaveBalances')
            ->orderBy('last_name')
            ->get()
            ->map(fn($emp) => [
                'id' => $emp->id,
                'full_name' => $emp->full_name,
                'first_name' => $emp->first_name,
                'last_name' => $emp->last_name,
                'position' => $emp->position,
                'leave_balances' => $emp->leaveBalances->map(fn($lb) => [
                    'leave_type_id' => $lb->leave_type_id,
                    'balance' => (float) $lb->balance,
                ]),
            ]);

        $leaveTypes = LeaveType::orderBy('name')->get()->map(fn($lt) => [
            'id' => $lt->id,
            'name' => $lt->name,
            'is_credit_based' => $lt->is_credit_based,
            'max_days' => $lt->max_days,
        ]);

        return response()->json([
            'employees' => $employees,
            'leave_types' => $leaveTypes,
        ]);
    }

    // ─── Admin: File leave on behalf of an employee ──────────────────

    /**
     * Admin submits a leave application on behalf of an employee in their
     * department. Skips admin-approval step → status goes to PENDING_HR.
     */
    public function adminStore(Request $request): JsonResponse
    {
        $admin = $request->user();
        if (!$admin instanceof DepartmentAdmin) {
            return response()->json(['message' => 'Only department admins can file leave on behalf of employees.'], 403);
        }

        $validated = $request->validate([
            'employee_id' => ['required', 'integer', 'exists:employees,id'],
            'leave_type_id' => ['required', 'integer', 'exists:leave_types,id'],
            'start_date' => ['required', 'date'],
            'end_date' => ['required', 'date', 'after_or_equal:start_date'],
            'total_days' => ['required', 'numeric', 'min:0.5', 'max:365'],
            'reason' => ['nullable', 'string', 'max:2000'],
        ]);

        // Verify the employee belongs to the admin's department
        $employee = Employee::find($validated['employee_id']);
        if (!$employee || (int) $employee->department_id !== (int) $admin->department_id) {
            return response()->json(['message' => 'You can only file leave for employees in your department.'], 403);
        }

        // Check balance for credit-based leave types
        $leaveType = LeaveType::find($validated['leave_type_id']);
        if ($leaveType && $leaveType->is_credit_based) {
            $balance = LeaveBalance::where('employee_id', $employee->id)
                ->where('leave_type_id', $validated['leave_type_id'])
                ->first();

            $currentBalance = $balance ? (float) $balance->balance : 0;

            if ($currentBalance < (float) $validated['total_days']) {
                return response()->json([
                    'message' => "Insufficient leave balance. Employee has {$currentBalance} day(s) available but requested {$validated['total_days']} day(s).",
                    'errors' => [
                        'total_days' => ["Insufficient leave balance. Available: {$currentBalance} day(s)."],
                    ],
                ], 422);
            }
        }

        $app = DB::transaction(function () use ($validated, $employee, $admin) {
            // Skip admin approval — go straight to PENDING_HR
            $application = LeaveApplication::create([
                'employee_id' => $employee->id,
                'leave_type_id' => $validated['leave_type_id'],
                'start_date' => $validated['start_date'],
                'end_date' => $validated['end_date'],
                'total_days' => $validated['total_days'],
                'reason' => $validated['reason'] ?? null,
                'status' => LeaveApplication::STATUS_PENDING_HR,
                'admin_id' => $admin->id,
                'admin_approved_at' => now(),
            ]);

            // Log the submission
            LeaveApplicationLog::create([
                'leave_application_id' => $application->id,
                'action' => LeaveApplicationLog::ACTION_SUBMITTED,
                'performed_by_type' => LeaveApplicationLog::PERFORMER_ADMIN,
                'performed_by_id' => $admin->id,
                'created_at' => now(),
            ]);

            // Log the auto-approval by admin
            LeaveApplicationLog::create([
                'leave_application_id' => $application->id,
                'action' => LeaveApplicationLog::ACTION_ADMIN_APPROVED,
                'performed_by_type' => LeaveApplicationLog::PERFORMER_ADMIN,
                'performed_by_id' => $admin->id,
                'remarks' => 'Filed by department admin on behalf of employee.',
                'created_at' => now(),
            ]);

            return $application;
        });

        // Notify the employee about the filed leave
        $app->load('leaveType');
        $empAccount = EmployeeAccount::where('employee_id', $employee->id)->first();
        if ($empAccount) {
            Notification::send(
                $empAccount,
                Notification::TYPE_LEAVE_PENDING,
                'Leave Filed by Admin',
                "Your department admin has filed a {$app->leaveType->name} leave application ({$app->total_days} day(s)) on your behalf. It has been forwarded to HR for approval.",
                $app->id
            );
        }

        // Notify all HR accounts about the new pending application
        $hrAccounts = HRAccount::all();
        foreach ($hrAccounts as $hrAccount) {
            Notification::send(
                $hrAccount,
                Notification::TYPE_LEAVE_REQUEST,
                'Leave Application Pending HR Review',
                "{$employee->full_name}'s {$app->leaveType->name} leave ({$app->total_days} day(s)) was filed by admin and awaits your review.",
                $app->id
            );
        }

        return response()->json([
            'message' => 'Leave application filed successfully and forwarded to HR.',
            'application' => $this->formatApplication($app->fresh(['leaveType', 'employee'])),
        ], 201);
    }

    // ═══════════════════════════════════════════════════════════════════
    //  HELPERS
    // ═══════════════════════════════════════════════════════════════════

    private function statusToFrontend(string $status): string
    {
        return match ($status) {
            LeaveApplication::STATUS_PENDING_ADMIN => 'Pending Admin',
            LeaveApplication::STATUS_PENDING_HR => 'Pending HR',
            LeaveApplication::STATUS_APPROVED => 'Approved',
            LeaveApplication::STATUS_REJECTED => 'Rejected',
            default => $status,
        };
    }

    /**
     * Format total days for display: remove trailing .00, proper singular/plural.
     * e.g. 4.00 → "4 days", 1.00 → "1 day", 0.50 → "0.5 days"
     */
    private static function formatDays($days): string
    {
        $num = (float) $days;
        $display = ($num == (int) $num) ? (int) $num : $num;
        return $display . ' ' . ($num == 1 ? 'day' : 'days');
    }

    private function formatApplication(LeaveApplication $app): array
    {
        $applicantName = $app->employee ? $app->employee->full_name : ($app->applicantAdmin ? $app->applicantAdmin->full_name : 'Unknown');
        $office = $app->employee?->department?->name ?? ($app->applicantAdmin?->department?->name ?? '');

        $data = [
            'id' => $app->id,
            'employee_id' => $app->employee_id,
            'applicant_admin_id' => $app->applicant_admin_id,
            'leave_type_id' => $app->leave_type_id,
            'leaveType' => $app->leaveType?->name ?? 'Unknown',
            'startDate' => $app->start_date ? \Carbon\Carbon::parse($app->start_date)->toDateString() : '',
            'endDate' => $app->end_date ? \Carbon\Carbon::parse($app->end_date)->toDateString() : '',
            'days' => (float) $app->total_days,
            'reason' => $app->reason,
            'status' => $this->statusToFrontend($app->status),
            'rawStatus' => $app->status,
            'dateFiled' => $app->created_at ? $app->created_at->toDateString() : '',
            'remarks' => $app->remarks,
            'admin_id' => $app->admin_id,
            'hr_id' => $app->hr_id,
            'admin_approved_at' => $app->admin_approved_at?->toIso8601String(),
            'hr_approved_at' => $app->hr_approved_at?->toIso8601String(),
            'applicantName' => $applicantName,
            'office' => $office,
        ];

        // Include employee info when loaded (for legacy frontend expectations)
        if ($app->relationLoaded('employee') && $app->employee) {
            $data['employee'] = [
                'id' => $app->employee->id,
                'first_name' => $app->employee->first_name,
                'last_name' => $app->employee->last_name,
                'full_name' => $app->employee->full_name,
                'position' => $app->employee->position,
                'department' => $app->employee->department?->name,
            ];
        }

        return $data;
    }
}
