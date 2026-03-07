<?php

namespace App\Http\Controllers;

use App\Models\DepartmentAdmin;
use App\Models\Employee;
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
 * This system uses ERMS ControlNo as the authoritative employee identifier.
 * Employee records are resolved from LMS tblEmployees.
 */
class LeaveApplicationController extends Controller
{
    public function __construct()
    {
    }
    // ─── Employee: List own applications ──────────────────────────────

    public function index(Request $request): JsonResponse
    {
        $account = $request->user();
        $controlNo = $account->erms_control_no ?? $account->employee_id ?? null;
        if (!is_object($account) || $controlNo === null) {
            return response()->json(['message' => 'Only employee accounts can list their leave applications.'], 403);
        }

        $applications = LeaveApplication::with('leaveType')
            ->where('erms_control_no', $controlNo)
            ->orderByDesc('created_at')
            ->get();

        return response()->json([
            'applications' => $applications->map(fn($app) => $this->formatApplication($app)),
        ]);
    }

    // ─── Employee: Fetch leave balance for monetization ─────────────

    /**
     * GET /employee/leave-balance/{leaveTypeId}
     * Returns the current balance for a specific leave type.
     * Used by the monetization form to show available credits.
     */
    public function getLeaveBalance(Request $request, int $leaveTypeId): JsonResponse
    {
        $account = $request->user();
        $controlNo = $account->erms_control_no ?? $account->employee_id ?? null;
        if (!is_object($account) || $controlNo === null) {
            return response()->json(['message' => 'Only employee accounts can access this endpoint.'], 403);
        }

        $employee = Employee::find($controlNo);
        if (!$employee) {
            return response()->json(['message' => 'Employee record not found.'], 404);
        }

        $leaveType = LeaveType::find($leaveTypeId);
        if (!$leaveType) {
            return response()->json(['message' => 'Leave type not found.'], 404);
        }

        $balance = LeaveBalance::where('employee_id', $employee->control_no)
            ->where('leave_type_id', $leaveTypeId)
            ->first();

        return response()->json([
            'leave_type_id' => $leaveType->id,
            'leave_type_name' => $leaveType->name,
            'balance' => $balance ? (float) $balance->balance : 0,
        ]);
    }

    /**
     * GET /erms/leave-balance/{id}
     * API-key protected endpoint for ERMS-to-LMS integration.
     *
     * Supports either:
     * - /erms/leave-balance/{leaveTypeId}?erms_control_no={controlNo}
     * - /erms/leave-balance/{controlNo}?leave_type_id={leaveTypeId}
     */
    public function ermsGetLeaveBalance(Request $request, int $id): JsonResponse
    {
        $validated = $request->validate([
            'erms_control_no' => ['nullable', 'string', 'regex:/^\d+$/'],
            'employee_id' => ['nullable', 'string', 'regex:/^\d+$/'],
            'leave_type_id' => ['nullable', 'integer', 'exists:tblLeaveTypes,id'],
        ]);

        $queryControlNo = $validated['erms_control_no'] ?? $validated['employee_id'] ?? null;
        $queryLeaveTypeId = $validated['leave_type_id'] ?? null;

        if ($queryControlNo !== null && $queryLeaveTypeId === null) {
            $controlNo = trim((string) $queryControlNo);
            $leaveTypeId = $id;
        } elseif ($queryControlNo === null && $queryLeaveTypeId !== null) {
            $controlNo = (string) $id;
            $leaveTypeId = (int) $queryLeaveTypeId;
        } elseif ($queryControlNo !== null && $queryLeaveTypeId !== null) {
            $controlNo = trim((string) $queryControlNo);
            $leaveTypeId = (int) $queryLeaveTypeId;
        } else {
            return response()->json([
                'message' => 'Provide either erms_control_no (or employee_id), or leave_type_id query parameter.',
            ], 422);
        }

        $employee = $this->findEmployeeByControlNo($controlNo);
        if (!$employee) {
            return response()->json(['message' => 'Employee record not found.'], 404);
        }

        $leaveType = LeaveType::find($leaveTypeId);
        if (!$leaveType) {
            return response()->json(['message' => 'Leave type not found.'], 404);
        }

        $balance = LeaveBalance::where('employee_id', $employee->control_no)
            ->where('leave_type_id', $leaveTypeId)
            ->first();

        return response()->json([
            'employee_id' => (string) $employee->control_no,
            'leave_type_id' => $leaveType->id,
            'leave_type_name' => $leaveType->name,
            'balance' => $balance ? (float) $balance->balance : 0,
        ]);
    }

    /**
     * GET /erms/leave-balances/{controlNo}
     * API-key protected endpoint for loading all leave balances in one request.
     */
    public function ermsGetLeaveBalances(Request $request, string $controlNo): JsonResponse
    {
        if (!preg_match('/^\d+$/', $controlNo)) {
            return response()->json(['message' => 'Invalid control number.'], 422);
        }

        $employee = $this->findEmployeeByControlNo($controlNo);
        if (!$employee) {
            return response()->json(['message' => 'Employee record not found.'], 404);
        }

        $types = LeaveType::query()
            ->select(['id', 'name'])
            ->orderBy('name')
            ->get();

        $balancesByType = LeaveBalance::query()
            ->where('employee_id', $employee->control_no)
            ->pluck('balance', 'leave_type_id');

        $balances = $types->map(function (LeaveType $type) use ($balancesByType) {
            $raw = $balancesByType->get($type->id);
            return [
                'leave_type_id' => $type->id,
                'leave_type_name' => $type->name,
                'balance' => $raw !== null ? (float) $raw : 0.0,
            ];
        })->values();

        return response()->json([
            'employee_id' => (string) $employee->control_no,
            'balances' => $balances,
        ]);
    }

    /**
     * GET /apply-leave (and aliases)
     * API-key protected endpoint for ERMS/HRPDS personal leave records listing.
     */
    public function ermsIndex(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'erms_control_no' => ['nullable', 'string', 'regex:/^\d+$/'],
            'employee_id' => ['nullable', 'string', 'regex:/^\d+$/'],
        ]);

        $controlNo = trim((string) ($validated['erms_control_no'] ?? $validated['employee_id'] ?? ''));
        if ($controlNo === '') {
            return response()->json([
                'message' => 'The erms_control_no (or employee_id) query parameter is required.',
            ], 422);
        }

        $employee = $this->findEmployeeByControlNo($controlNo);
        if (!$employee) {
            return response()->json(['message' => 'Employee record not found.'], 404);
        }

        $applications = LeaveApplication::query()
            ->with(['leaveType', 'employee', 'applicantAdmin.department', 'logs'])
            ->where(function ($query) use ($controlNo) {
                $query->where('erms_control_no', $controlNo)
                    ->orWhereRaw('TRY_CONVERT(INT, erms_control_no) = TRY_CONVERT(INT, ?)', [$controlNo]);
            })
            ->orderByDesc('created_at')
            ->get();

        $actorDirectory = $this->buildWorkflowActorDirectory($applications);

        $leaveTypes = LeaveType::query()
            ->orderBy('name')
            ->get(['id', 'name', 'category', 'max_days', 'is_credit_based']);

        return response()->json([
            'employee_id' => (string) $employee->control_no,
            'leave_types' => $leaveTypes,
            'applications' => $applications
                ->map(fn(LeaveApplication $app) => $this->formatErmsApplication($app, $actorDirectory))
                ->values(),
        ]);
    }

    /**
     * POST /erms/apply-leave and POST /apply-leave
     * API-key protected endpoint for ERMS-to-LMS leave application submission.
     */
    public function ermsStore(Request $request): JsonResponse
    {
        // Accept common ERMS key variants so frontend payload shape is less brittle.
        $request->merge(array_filter([
            'erms_control_no' => $request->input('erms_control_no')
                ?? $request->input('employee_id')
                ?? $request->input('employeeId')
                ?? $request->input('control_no')
                ?? $request->input('controlNo'),
            'employee_id' => $request->input('employee_id')
                ?? $request->input('employeeId')
                ?? $request->input('erms_control_no')
                ?? $request->input('control_no')
                ?? $request->input('controlNo'),
            'is_monetization' => $request->input('is_monetization')
                ?? $request->input('isMonetization'),
        ], static fn ($value) => $value !== null && $value !== ''));

        $baseValidated = $request->validate([
            'erms_control_no' => ['nullable', 'string', 'regex:/^\d+$/', 'required_without:employee_id'],
            'employee_id' => ['nullable', 'string', 'regex:/^\d+$/', 'required_without:erms_control_no'],
            'is_monetization' => ['nullable', 'boolean'],
        ]);

        $controlNo = trim((string) ($baseValidated['erms_control_no'] ?? $baseValidated['employee_id']));

        // Normalize alias so downstream validation/helpers can rely on one key.
        $request->merge(['erms_control_no' => $controlNo]);

        // Accept snake_case and camelCase fields for leave payload.
        $request->merge(array_filter([
            'leave_type_id' => $request->input('leave_type_id')
                ?? $request->input('leaveTypeId')
                ?? $request->input('leave_type'),
            'start_date' => $request->input('start_date')
                ?? $request->input('startDate'),
            'end_date' => $request->input('end_date')
                ?? $request->input('endDate'),
            'total_days' => $request->input('total_days')
                ?? $request->input('totalDays')
                ?? $request->input('days'),
            'reason' => $request->input('reason')
                ?? $request->input('remarks'),
            'selected_dates' => $request->input('selected_dates')
                ?? $request->input('selectedDates'),
            'commutation' => $request->input('commutation')
                ?? $request->input('commutation_option'),
        ], static fn ($value) => $value !== null && $value !== ''));

        $employee = $this->findEmployeeByControlNo($controlNo);

        if (!$employee) {
            return response()->json(['error' => 'Employee not found'], 404);
        }

        $actor = (object) ['id' => (int) ltrim((string) $employee->control_no, '0')];
        $isMonetization = (bool) ($baseValidated['is_monetization'] ?? false);

        if ($isMonetization) {
            return $this->storeMonetization($request, $employee, $actor);
        }

        $validated = $request->validate([
            'leave_type_id' => ['required', 'integer', 'exists:tblLeaveTypes,id'],
            'start_date' => ['required', 'date'],
            'end_date' => ['required', 'date', 'after_or_equal:start_date'],
            'total_days' => ['required', 'numeric', 'min:0.5', 'max:365'],
            'reason' => ['nullable', 'string', 'max:2000'],
            'selected_dates' => ['nullable', 'array'],
            'selected_dates.*' => ['date'],
            'commutation' => ['nullable', 'string', 'in:Not Requested,Requested'],
        ]);

        $eligibility = $this->validateRegularLeaveEligibility(
            (string) $employee->control_no,
            (int) $validated['leave_type_id'],
            (float) $validated['total_days']
        );
        if ($eligibility instanceof JsonResponse) {
            return $eligibility;
        }

        $app = DB::transaction(function () use ($validated, $employee, $actor) {
            $application = LeaveApplication::create([
                'erms_control_no' => (int) $employee->control_no,
                'leave_type_id' => $validated['leave_type_id'],
                'start_date' => $validated['start_date'],
                'end_date' => $validated['end_date'],
                'total_days' => $validated['total_days'],
                'reason' => $validated['reason'] ?? null,
                'selected_dates' => $validated['selected_dates'] ?? null,
                'commutation' => $validated['commutation'] ?? 'Not Requested',
                'status' => LeaveApplication::STATUS_PENDING_ADMIN,
            ]);

            LeaveApplicationLog::create([
                'leave_application_id' => $application->id,
                'action' => LeaveApplicationLog::ACTION_SUBMITTED,
                'performed_by_type' => LeaveApplicationLog::PERFORMER_EMPLOYEE,
                'performed_by_id' => $actor->id,
                'created_at' => now(),
            ]);

            return $application;
        });

        $app->load('leaveType');
        $admins = DepartmentAdmin::whereHas('department', fn($q) => $q->where('name', $employee->office))->get();
        foreach ($admins as $deptAdmin) {
            Notification::send(
                $deptAdmin,
                Notification::TYPE_LEAVE_REQUEST,
                'New Leave Application',
                trim(($employee->firstname ?? '') . ' ' . ($employee->surname ?? '')) . " submitted a {$app->leaveType->name} leave request (" . self::formatDays($app->total_days) . ").",
                $app->id
            );
        }

        return response()->json([
            'message' => 'Leave application submitted successfully.',
            'application' => $this->formatApplication($app->fresh('leaveType')),
        ], 201);
    }

    // ─── Employee: Submit new leave application ──────────────────────

    /**
     * POST /erms/cancel-leave/{id?}
     * POST /cancel-leave/{id?}
     * POST /leave-applications/{id}/cancel
     *
     * API-key protected endpoint for ERMS-to-LMS leave cancellation.
     * Cancels only pending applications owned by the provided employee.
     */
    public function ermsCancel(Request $request, ?int $id = null): JsonResponse
    {
        $routeId = $id;

        // Accept common ERMS key variants so cancellation requests are resilient.
        $request->merge(array_filter([
            'leave_application_id' => $request->input('leave_application_id')
                ?? $request->input('application_id')
                ?? $request->input('id')
                ?? $routeId,
            'erms_control_no' => $request->input('erms_control_no')
                ?? $request->input('employee_id')
                ?? $request->input('employeeId')
                ?? $request->input('control_no')
                ?? $request->input('controlNo'),
            'employee_id' => $request->input('employee_id')
                ?? $request->input('employeeId')
                ?? $request->input('erms_control_no')
                ?? $request->input('control_no')
                ?? $request->input('controlNo'),
            'cancellation_reason' => $request->input('cancellation_reason')
                ?? $request->input('cancel_reason')
                ?? $request->input('cancelReason')
                ?? $request->input('reason')
                ?? $request->input('remarks'),
        ], static fn($value) => $value !== null && $value !== ''));

        $validated = $request->validate([
            'leave_application_id' => ['required', 'integer'],
            'erms_control_no' => ['nullable', 'string', 'regex:/^\d+$/', 'required_without:employee_id'],
            'employee_id' => ['nullable', 'string', 'regex:/^\d+$/', 'required_without:erms_control_no'],
            'cancellation_reason' => ['nullable', 'string', 'max:2000'],
        ]);

        $applicationId = (int) $validated['leave_application_id'];
        if ($routeId !== null && $applicationId !== $routeId) {
            return response()->json([
                'message' => 'Route ID and payload leave_application_id must match.',
            ], 422);
        }

        $controlNo = trim((string) ($validated['erms_control_no'] ?? $validated['employee_id']));
        $employee = $this->findEmployeeByControlNo($controlNo);
        if (!$employee) {
            return response()->json(['message' => 'Employee record not found.'], 404);
        }

        $app = LeaveApplication::query()
            ->with('leaveType')
            ->where('id', $applicationId)
            ->where(function ($query) use ($controlNo) {
                $query->where('erms_control_no', $controlNo)
                    ->orWhereRaw('TRY_CONVERT(INT, erms_control_no) = TRY_CONVERT(INT, ?)', [$controlNo]);
            })
            ->first();

        if (!$app) {
            return response()->json(['message' => 'Leave application not found for this employee.'], 404);
        }

        $cancelableStatuses = [
            LeaveApplication::STATUS_PENDING_ADMIN,
            LeaveApplication::STATUS_PENDING_HR,
        ];

        if (!in_array($app->status, $cancelableStatuses, true)) {
            return response()->json([
                'message' => "Cannot cancel: application status is '{$this->ermsStatusLabel($app->status)}'. Only pending applications can be cancelled.",
            ], 422);
        }

        $reason = trim((string) ($validated['cancellation_reason'] ?? ''));
        $remarks = $reason !== ''
            ? "Cancelled via ERMS: {$reason}"
            : 'Cancelled via ERMS';
        $statusBeforeCancel = $app->status;

        $performedById = (int) ltrim((string) $employee->control_no, '0');
        if ($performedById <= 0) {
            $performedById = (int) ($app->erms_control_no ?: 1);
        }

        DB::transaction(function () use ($app, $remarks, $performedById): void {
            $app->update([
                'status' => LeaveApplication::STATUS_REJECTED,
                'remarks' => $remarks,
            ]);

            // Reuse REJECTED action because current log schema has no dedicated CANCELLED enum.
            LeaveApplicationLog::create([
                'leave_application_id' => $app->id,
                'action' => LeaveApplicationLog::ACTION_ADMIN_REJECTED,
                'performed_by_type' => LeaveApplicationLog::PERFORMER_EMPLOYEE,
                'performed_by_id' => $performedById,
                'remarks' => $remarks,
                'created_at' => now(),
            ]);
        });

        $employeeName = trim(($employee->firstname ?? '') . ' ' . ($employee->surname ?? ''));
        if ($employeeName === '') {
            $employeeName = 'Employee ' . (string) $employee->control_no;
        }
        $leaveTypeName = $app->leaveType?->name ?? 'leave';
        $pendingStage = $statusBeforeCancel === LeaveApplication::STATUS_PENDING_HR
            ? 'pending HR review'
            : 'pending department review';
        $message = "{$employeeName} cancelled a {$leaveTypeName} application ({$pendingStage}).";
        if ($reason !== '') {
            $message .= " Reason: {$reason}";
        }

        $title = 'Leave Application Cancelled by Employee';
        $admins = DepartmentAdmin::whereHas('department', fn($q) => $q->where('name', $employee->office))->get();
        foreach ($admins as $deptAdmin) {
            Notification::send(
                $deptAdmin,
                Notification::TYPE_LEAVE_CANCELLED,
                $title,
                $message,
                $app->id
            );
        }

        $hrAccounts = HRAccount::all();
        foreach ($hrAccounts as $hrAccount) {
            Notification::send(
                $hrAccount,
                Notification::TYPE_LEAVE_CANCELLED,
                $title,
                $message,
                $app->id
            );
        }

        return response()->json([
            'message' => 'Leave application cancelled successfully.',
            'application' => $this->formatApplication($app->fresh('leaveType')),
        ]);
    }

    /**
     * POST /erms/request-edit-leave/{id?}
     * POST /request-edit-leave/{id?}
     * POST /leave-applications/{id}/request-edit
     * POST /leave-applications/{id}/edit-request
     * POST /leave-applications/{id}/actions/request-edit
     * POST /leave-applications/request-edit
     *
     * API-key protected endpoint for ERMS-to-LMS leave edit request.
     * Accepts reason/remarks and notifies admin/HR for pending applications.
     */
    public function ermsRequestEdit(Request $request, ?int $id = null): JsonResponse
    {
        $routeId = $id;

        // Accept common ERMS key variants so request-edit payload is resilient.
        $request->merge(array_filter([
            'leave_application_id' => $request->input('leave_application_id')
                ?? $request->input('application_id')
                ?? $request->input('id')
                ?? $routeId,
            'erms_control_no' => $request->input('erms_control_no')
                ?? $request->input('employee_id')
                ?? $request->input('employeeId')
                ?? $request->input('control_no')
                ?? $request->input('controlNo'),
            'employee_id' => $request->input('employee_id')
                ?? $request->input('employeeId')
                ?? $request->input('erms_control_no')
                ?? $request->input('control_no')
                ?? $request->input('controlNo'),
            'edit_reason' => $request->input('edit_reason')
                ?? $request->input('request_reason')
                ?? $request->input('editReason')
                ?? $request->input('reason')
                ?? $request->input('remarks'),
        ], static fn($value) => $value !== null && $value !== ''));

        $validated = $request->validate([
            'leave_application_id' => ['required', 'integer'],
            'erms_control_no' => ['nullable', 'string', 'regex:/^\d+$/', 'required_without:employee_id'],
            'employee_id' => ['nullable', 'string', 'regex:/^\d+$/', 'required_without:erms_control_no'],
            'edit_reason' => ['nullable', 'string', 'max:2000'],
        ]);

        $applicationId = (int) $validated['leave_application_id'];
        if ($routeId !== null && $applicationId !== $routeId) {
            return response()->json([
                'message' => 'Route ID and payload leave_application_id must match.',
            ], 422);
        }

        $controlNo = trim((string) ($validated['erms_control_no'] ?? $validated['employee_id']));
        $employee = $this->findEmployeeByControlNo($controlNo);
        if (!$employee) {
            return response()->json(['message' => 'Employee record not found.'], 404);
        }

        $app = LeaveApplication::query()
            ->with('leaveType')
            ->where('id', $applicationId)
            ->where(function ($query) use ($controlNo) {
                $query->where('erms_control_no', $controlNo)
                    ->orWhereRaw('TRY_CONVERT(INT, erms_control_no) = TRY_CONVERT(INT, ?)', [$controlNo]);
            })
            ->first();

        if (!$app) {
            return response()->json(['message' => 'Leave application not found for this employee.'], 404);
        }

        $editableStatuses = [
            LeaveApplication::STATUS_PENDING_ADMIN,
            LeaveApplication::STATUS_PENDING_HR,
        ];

        if (!in_array($app->status, $editableStatuses, true)) {
            return response()->json([
                'message' => "Cannot request edit: application status is '{$this->ermsStatusLabel($app->status)}'. Only pending applications can request edits.",
            ], 422);
        }

        $reason = trim((string) ($validated['edit_reason'] ?? ''));
        $remarksLine = $reason !== ''
            ? "Edit requested via ERMS: {$reason}"
            : 'Edit requested via ERMS';

        $existingRemarks = trim((string) ($app->remarks ?? ''));
        $updatedRemarks = $existingRemarks === ''
            ? $remarksLine
            : "{$existingRemarks}\n{$remarksLine}";

        $performedById = (int) ltrim((string) $employee->control_no, '0');
        if ($performedById <= 0) {
            $performedById = (int) ($app->erms_control_no ?: 1);
        }

        DB::transaction(function () use ($app, $updatedRemarks, $remarksLine, $performedById): void {
            $app->update([
                // Keep pending status; this endpoint requests edit review, not status transition.
                'remarks' => $updatedRemarks,
            ]);

            // Reuse SUBMITTED action because current log schema has no dedicated EDIT_REQUESTED enum.
            LeaveApplicationLog::create([
                'leave_application_id' => $app->id,
                'action' => LeaveApplicationLog::ACTION_SUBMITTED,
                'performed_by_type' => LeaveApplicationLog::PERFORMER_EMPLOYEE,
                'performed_by_id' => $performedById,
                'remarks' => $remarksLine,
                'created_at' => now(),
            ]);
        });

        $employeeName = trim(($employee->firstname ?? '') . ' ' . ($employee->surname ?? ''));
        if ($employeeName === '') {
            $employeeName = 'Employee ' . (string) $employee->control_no;
        }
        $leaveTypeName = $app->leaveType?->name ?? 'leave';
        $pendingStage = $app->status === LeaveApplication::STATUS_PENDING_HR
            ? 'pending HR review'
            : 'pending department review';
        $message = "{$employeeName} requested an edit for a {$leaveTypeName} application ({$pendingStage}).";
        if ($reason !== '') {
            $message .= " Reason: {$reason}";
        }

        $title = 'Leave Application Edit Requested';
        $admins = DepartmentAdmin::whereHas('department', fn($q) => $q->where('name', $employee->office))->get();
        foreach ($admins as $deptAdmin) {
            Notification::send(
                $deptAdmin,
                Notification::TYPE_LEAVE_EDIT_REQUEST,
                $title,
                $message,
                $app->id
            );
        }

        $hrAccounts = HRAccount::all();
        foreach ($hrAccounts as $hrAccount) {
            Notification::send(
                $hrAccount,
                Notification::TYPE_LEAVE_EDIT_REQUEST,
                $title,
                $message,
                $app->id
            );
        }

        return response()->json([
            'message' => 'Leave edit request submitted successfully.',
            'application' => $this->formatApplication($app->fresh('leaveType')),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $account = $request->user();
        if (!is_object($account)) {
            return response()->json(['message' => 'Only employee accounts can submit leave applications.'], 403);
        }

        $baseValidated = $request->validate([
            'erms_control_no' => ['required', 'integer'],
            'is_monetization' => ['nullable', 'boolean'],
        ]);

        // This system uses ERMS ControlNo as the authoritative employee identifier.
        // Employee records are resolved from LMS tblEmployees.
        $employee = DB::table('tblEmployees')
            ->where('control_no', $baseValidated['erms_control_no'])
            ->first();

        if (!$employee) {
            return response()->json(['error' => 'Employee not found'], 404);
        }

        // Detect monetization request
        $isMonetization = (bool) ($baseValidated['is_monetization'] ?? false);

        if ($isMonetization) {
            return $this->storeMonetization($request, $employee, $account);
        }

        $validated = $request->validate([
            'erms_control_no' => ['required', 'integer'],
            'leave_type_id' => ['required', 'integer', 'exists:tblLeaveTypes,id'],
            'start_date' => ['required', 'date'],
            'end_date' => ['required', 'date', 'after_or_equal:start_date'],
            'total_days' => ['required', 'numeric', 'min:0.5', 'max:365'],
            'reason' => ['nullable', 'string', 'max:2000'],
            'selected_dates' => ['nullable', 'array'],
            'selected_dates.*' => ['date'],
            'commutation' => ['nullable', 'string', 'in:Not Requested,Requested'],
        ]);

        $eligibility = $this->validateRegularLeaveEligibility(
            (string) $employee->control_no,
            (int) $validated['leave_type_id'],
            (float) $validated['total_days']
        );
        if ($eligibility instanceof JsonResponse) {
            return $eligibility;
        }

        $app = DB::transaction(function () use ($validated, $employee, $account) {
            $application = LeaveApplication::create([
                'erms_control_no' => (int) $employee->control_no,
                'leave_type_id' => $validated['leave_type_id'],
                'start_date' => $validated['start_date'],
                'end_date' => $validated['end_date'],
                'total_days' => $validated['total_days'],
                'reason' => $validated['reason'] ?? null,
                'selected_dates' => $validated['selected_dates'] ?? null,
                'commutation' => $validated['commutation'] ?? 'Not Requested',
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
        $admins = DepartmentAdmin::whereHas('department', fn($q) => $q->where('name', $employee->office))->get();
        foreach ($admins as $deptAdmin) {
            Notification::send(
                $deptAdmin,
                Notification::TYPE_LEAVE_REQUEST,
                'New Leave Application',
                trim(($employee->firstname ?? '') . ' ' . ($employee->surname ?? '')) . " submitted a {$app->leaveType->name} leave request (" . self::formatDays($app->total_days) . ").",
                $app->id
            );
        }

        return response()->json([
            'message' => 'Leave application submitted successfully.',
            'application' => $this->formatApplication($app->fresh('leaveType')),
        ], 201);
    }

    // ─── Employee: Submit monetization of leave credits ──────────────

    /**
     * Handle monetization submission with specific validation rules.
     * City Hall of Tagum policy: minimum 10 accumulated credits required.
     */
    private function storeMonetization(Request $request, object $employee, object $account): JsonResponse
    {
        $validated = $request->validate([
            'erms_control_no' => ['required', 'integer'],
            'leave_type_id' => ['required', 'integer', 'exists:tblLeaveTypes,id'],
            'total_days' => ['required', 'numeric', 'min:1', 'max:999'],
            'reason' => ['nullable', 'string', 'max:2000'],
            'salary' => ['nullable', 'numeric', 'min:0'],
        ]);

        // Validate that the selected leave type is either Vacation Leave or Sick Leave
        $leaveType = LeaveType::find($validated['leave_type_id']);
        if (!$leaveType || !in_array($leaveType->name, ['Vacation Leave', 'Sick Leave'], true)) {
            return response()->json([
                'message' => 'Monetization is only allowed for Vacation Leave or Sick Leave.',
                'errors' => [
                    'leave_type_id' => ['Monetization is only allowed for Vacation Leave or Sick Leave.'],
                ],
            ], 422);
        }

        // Retrieve current balance (always re-check in backend)
        $balance = LeaveBalance::where('employee_id', $employee->control_no)
            ->where('leave_type_id', $validated['leave_type_id'])
            ->first();

        $currentBalance = $balance ? (float) $balance->balance : 0;

        // Minimum 10 credits required for monetization
        if ($currentBalance < 10) {
            return response()->json([
                'message' => 'Minimum of 10 leave credits required for monetization.',
                'errors' => [
                    'total_days' => ['Minimum of 10 leave credits required for monetization. Current balance: ' . self::formatDays($currentBalance) . '.'],
                ],
            ], 422);
        }

        $requestedDays = (float) $validated['total_days'];

        // Cannot exceed available balance
        if ($requestedDays > $currentBalance) {
            return response()->json([
                'message' => 'Requested monetization days exceed available leave credits.',
                'errors' => [
                    'total_days' => ["Requested monetization days ({$requestedDays}) exceed available balance (" . self::formatDays($currentBalance) . ")."],
                ],
            ], 422);
        }

        // Compute equivalent amount if salary provided
        $equivalentAmount = null;
        $salary = $request->input('salary');
        if ($salary && is_numeric($salary) && (float) $salary > 0) {
            $dailyRate = (float) $salary / 22;
            $equivalentAmount = round($requestedDays * $dailyRate, 2);
        }

        $app = DB::transaction(function () use ($validated, $employee, $account, $equivalentAmount) {
            $application = LeaveApplication::create([
                'erms_control_no' => (int) $employee->control_no,
                'leave_type_id' => $validated['leave_type_id'],
                'start_date' => null,
                'end_date' => null,
                'total_days' => $validated['total_days'],
                'reason' => $validated['reason'] ?? null,
                'status' => LeaveApplication::STATUS_PENDING_ADMIN,
                'is_monetization' => true,
                'equivalent_amount' => $equivalentAmount,
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

        // Notify department admins
        $app->load('leaveType');
        $admins = DepartmentAdmin::whereHas('department', fn($q) => $q->where('name', $employee->office))->get();
        foreach ($admins as $deptAdmin) {
            Notification::send(
                $deptAdmin,
                Notification::TYPE_LEAVE_REQUEST,
                'Monetization Request',
                trim(($employee->firstname ?? '') . ' ' . ($employee->surname ?? '')) . " submitted a monetization request for {$app->leaveType->name} (" . self::formatDays($app->total_days) . ").",
                $app->id
            );
        }

        return response()->json([
            'message' => 'Monetization request submitted successfully.',
            'application' => $this->formatApplication($app->fresh('leaveType')),
            'equivalent_amount' => $equivalentAmount,
        ], 201);
    }

    // ─── Employee: View own single application ───────────────────────

    public function show(Request $request, LeaveApplication $leaveApplication): JsonResponse
    {
        $account = $request->user();
        $controlNo = $account->erms_control_no ?? $account->employee_id ?? null;
        if (!is_object($account) || $controlNo === null) {
            return response()->json(['message' => 'Only employee accounts can view leave applications.'], 403);
        }

        if ((string) $leaveApplication->erms_control_no !== (string) $controlNo) {
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

        $deptName = $admin->department?->name;
        $applications = LeaveApplication::with(['leaveType'])
            ->where('status', LeaveApplication::STATUS_PENDING_ADMIN)
            ->whereIn('erms_control_no', function ($query) use ($deptName) {
                $query->select('control_no')
                    ->from('tblEmployees')
                    ->where('office', $deptName);
            })
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

        $admin->loadMissing('department');
        $app = LeaveApplication::find($id);
        if (!$app) {
            return response()->json(['message' => 'Leave application not found.'], 404);
        }

        // Security: admin can only act on their own department's applications
        if ($app->employee?->office !== $admin->department?->name) {
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
        $app->load(['leaveType', 'employee']);
        $isMonetization = (bool) $app->is_monetization;
        $actionLabel = $isMonetization ? 'monetization request' : 'leave application';
        $titleLabel = $isMonetization ? 'Monetization' : 'Leave';
        $employeeName = $this->resolveEmployeeDisplayName($app);

        // Notify all HR accounts about the new pending application
        $hrAccounts = HRAccount::all();
        foreach ($hrAccounts as $hrAccount) {
            Notification::send(
                $hrAccount,
                Notification::TYPE_LEAVE_REQUEST,
                "{$titleLabel} Application Pending HR Review",
                "{$employeeName} submitted a {$app->leaveType->name} {$actionLabel} (" . self::formatDays($app->total_days) . ") that has been approved by admin and awaits your review.",
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

        $admin->loadMissing('department');
        $app = LeaveApplication::find($id);
        if (!$app) {
            return response()->json(['message' => 'Leave application not found.'], 404);
        }

        if ($app->employee?->office !== $admin->department?->name) {
            return response()->json(['message' => 'You can only manage applications from your department.'], 403);
        }

        if (!in_array($app->status, [
            LeaveApplication::STATUS_PENDING_ADMIN,
            LeaveApplication::STATUS_PENDING_HR,
        ], true)) {
            return response()->json([
                'message' => "Cannot reject: application status is '{$app->status}'. Expected 'PENDING_ADMIN' or 'PENDING_HR'."
            ], 422);
        }

        DB::transaction(function () use ($app, $admin, $request) {
            $app->update([
                'status' => LeaveApplication::STATUS_REJECTED,
                'admin_id' => $admin->id,
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
        $app->load('leaveType');
        $isMonetization = (bool) $app->is_monetization;
        $actionLabel = $isMonetization ? 'monetization request' : 'leave application';
        $titleLabel = $isMonetization ? 'Monetization' : 'Leave Application';

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

        $applications = LeaveApplication::with(['leaveType'])
            ->where('status', LeaveApplication::STATUS_PENDING_HR)
            ->orderByDesc('created_at')
            ->get();

        return response()->json([
            'applications' => $applications->map(fn($app) => $this->formatApplication($app)),
        ]);
    }

    /**
     * HR approves → status becomes APPROVED.
     * If leave type is credit-based OR is monetization, deduct from leave_balances inside a transaction.
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

        // Determine if balance deduction is needed
        $needsDeduction = ($app->leaveType && $app->leaveType->is_credit_based) || $app->is_monetization;

        if ($needsDeduction) {
            if ($app->erms_control_no) {
                // For monetization, deduct from the selected leave type (VL or SL)
                $deductTypeId = $app->leave_type_id;

                $balance = LeaveBalance::where('employee_id', $app->erms_control_no)
                    ->where('leave_type_id', $deductTypeId)
                    ->first();

                if (!$balance || (float) $balance->balance < (float) $app->total_days) {
                    $currentBalance = $balance ? (float) $balance->balance : 0;
                    $label = $app->is_monetization ? 'monetization' : 'leave';
                    return response()->json([
                        'message' => "Insufficient leave balance for {$label}. Current: " . self::formatDays($currentBalance) . ", Requested: " . self::formatDays($app->total_days) . ".",
                    ], 422);
                }

                // Extra check for monetization: minimum 10 credits
                if ($app->is_monetization && (float) $balance->balance < 10) {
                    return response()->json([
                        'message' => 'Minimum of 10 leave credits required for monetization.',
                    ], 422);
                }
            } elseif ($app->applicant_admin_id) {
                $balance = \App\Models\AdminLeaveBalance::where('admin_id', $app->applicant_admin_id)
                    ->where('leave_type_id', $app->leave_type_id)
                    ->where('year', now()->year)
                    ->first();

                if (!$balance || (float) $balance->balance < (float) $app->total_days) {
                    $currentBalance = $balance ? (float) $balance->balance : 0;
                    return response()->json([
                        'message' => "Insufficient leave balance. Current: " . self::formatDays($currentBalance) . ", Requested: " . self::formatDays($app->total_days) . ".",
                    ], 422);
                }
            }
        }

        DB::transaction(function () use ($app, $hr, $request, $needsDeduction) {
            $app->update([
                'status' => LeaveApplication::STATUS_APPROVED,
                'hr_id' => $hr->id,
                'hr_approved_at' => now(),
                'remarks' => $request->input('remarks'),
            ]);

            // Deduct balance for credit-based leave types and monetization
            if ($needsDeduction) {
                if ($app->erms_control_no) {
                    LeaveBalance::where('employee_id', $app->erms_control_no)
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
        $isMonetization = (bool) $app->is_monetization;
        $actionLabel = $isMonetization ? 'monetization request' : 'leave application';
        $titleLabel = $isMonetization ? 'Monetization Approved' : 'Leave Application Approved';
        $msg = "Your {$app->leaveType->name} {$actionLabel} (" . self::formatDays($app->total_days) . ") has been fully approved!";
        if ($app->applicant_admin_id) {
            Notification::send($app->applicantAdmin, Notification::TYPE_LEAVE_APPROVED, $titleLabel, $msg, $app->id);
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
                'hr_id' => $hr->id,
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

        // Notify the applicant that HR rejected
        $app->load('leaveType');
        $isMonetization = (bool) $app->is_monetization;
        $actionLabel = $isMonetization ? 'monetization request' : 'leave application';
        $titleLabel = $isMonetization ? 'Monetization' : 'Leave Application';
        $rejectMsg = "Your {$app->leaveType->name} {$actionLabel} was rejected by HR." . ($request->input('remarks') ? " Reason: {$request->input('remarks')}" : '');

        if ($app->applicant_admin_id) {
            Notification::send(
                $app->applicantAdmin,
                Notification::TYPE_LEAVE_REJECTED,
                "{$titleLabel} Rejected by HR",
                $rejectMsg,
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

        $admin->loadMissing('department');
        $deptName = $admin->department?->name;

        $employees = Employee::where('office', $deptName)
            ->with('leaveBalances')
            ->orderBy('surname')
            ->get()
            ->map(fn($emp) => [
                'control_no' => $emp->control_no,
                'full_name' => $emp->full_name,
                'firstname' => $emp->firstname,
                'surname' => $emp->surname,
                'designation' => $emp->designation,
                'office' => $emp->office,
                'salary' => $emp->rate_mon !== null ? (float) $emp->rate_mon : null,
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

        // Detect monetization request
        $isMonetization = (bool) $request->input('is_monetization', false);

        if ($isMonetization) {
            return $this->adminStoreMonetization($request, $admin);
        }

        $validated = $request->validate([
            'employee_id' => ['required', 'string', 'exists:tblEmployees,control_no'],
            'leave_type_id' => ['required', 'integer', 'exists:tblLeaveTypes,id'],
            'start_date' => ['required', 'date'],
            'end_date' => ['required', 'date', 'after_or_equal:start_date'],
            'total_days' => ['required', 'numeric', 'min:0.5', 'max:365'],
            'reason' => ['nullable', 'string', 'max:2000'],
            'selected_dates' => ['nullable', 'array'],
            'selected_dates.*' => ['date'],
            'commutation' => ['nullable', 'string', 'in:Not Requested,Requested'],
        ]);

        // Verify the employee belongs to the admin's department (by office name)
        $admin->loadMissing('department');
        $employee = Employee::find($validated['employee_id']);
        if (!$employee || $employee->office !== $admin->department?->name) {
            return response()->json(['message' => 'You can only file leave for employees in your department.'], 403);
        }

        $eligibility = $this->validateRegularLeaveEligibility(
            (string) $employee->control_no,
            (int) $validated['leave_type_id'],
            (float) $validated['total_days']
        );
        if ($eligibility instanceof JsonResponse) {
            return $eligibility;
        }

        $app = DB::transaction(function () use ($validated, $employee, $admin) {
            $application = LeaveApplication::create([
                'erms_control_no' => (int) $employee->control_no,
                'leave_type_id' => $validated['leave_type_id'],
                'start_date' => $validated['start_date'],
                'end_date' => $validated['end_date'],
                'total_days' => $validated['total_days'],
                'reason' => $validated['reason'] ?? null,
                'selected_dates' => $validated['selected_dates'] ?? null,
                'commutation' => $validated['commutation'] ?? 'Not Requested',
                'status' => LeaveApplication::STATUS_PENDING_HR,
                'admin_id' => $admin->id,
                'admin_approved_at' => now(),
            ]);

            LeaveApplicationLog::create([
                'leave_application_id' => $application->id,
                'action' => LeaveApplicationLog::ACTION_SUBMITTED,
                'performed_by_type' => LeaveApplicationLog::PERFORMER_ADMIN,
                'performed_by_id' => $admin->id,
                'created_at' => now(),
            ]);

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

        $app->load('leaveType');
        $employeeName = trim((string) (($employee->firstname ?? '') . ' ' . ($employee->surname ?? '')));
        if ($employeeName === '') {
            $employeeName = $this->resolveEmployeeDisplayName($app);
        }

        $hrAccounts = HRAccount::all();
        foreach ($hrAccounts as $hrAccount) {
            Notification::send(
                $hrAccount,
                Notification::TYPE_LEAVE_REQUEST,
                'Leave Application Pending HR Review',
                "{$employeeName} filed a {$app->leaveType->name} leave (" . self::formatDays($app->total_days) . ") through department admin and it awaits your review.",
                $app->id
            );
        }

        return response()->json([
            'message' => 'Leave application filed successfully and forwarded to HR.',
            'application' => $this->formatApplication($app->fresh(['leaveType'])),
        ], 201);
    }

    // ─── Admin: File monetization on behalf of an employee ─────────────

    /**
     * Handle monetization filed by admin on behalf of an employee.
     */
    private function adminStoreMonetization(Request $request, DepartmentAdmin $admin): JsonResponse
    {
        $validated = $request->validate([
            'employee_id' => ['required', 'string', 'exists:tblEmployees,control_no'],
            'leave_type_id' => ['required', 'integer', 'exists:tblLeaveTypes,id'],
            'total_days' => ['required', 'numeric', 'min:1', 'max:999'],
            'reason' => ['nullable', 'string', 'max:2000'],
            'salary' => ['nullable', 'numeric', 'min:0'],
        ]);

        $admin->loadMissing('department');
        $employee = Employee::find($validated['employee_id']);
        if (!$employee || $employee->office !== $admin->department?->name) {
            return response()->json(['message' => 'You can only file for employees in your department.'], 403);
        }

        $leaveType = LeaveType::find($validated['leave_type_id']);
        if (!$leaveType || !in_array($leaveType->name, ['Vacation Leave', 'Sick Leave'], true)) {
            return response()->json([
                'message' => 'Monetization is only allowed for Vacation Leave or Sick Leave.',
                'errors' => ['leave_type_id' => ['Monetization is only allowed for Vacation Leave or Sick Leave.']],
            ], 422);
        }

        $balance = LeaveBalance::where('employee_id', $employee->control_no)
            ->where('leave_type_id', $validated['leave_type_id'])
            ->first();

        $currentBalance = $balance ? (float) $balance->balance : 0;

        if ($currentBalance < 10) {
            return response()->json([
                'message' => 'Minimum of 10 leave credits required for monetization.',
                'errors' => ['total_days' => ['Minimum of 10 leave credits required. Current: ' . self::formatDays($currentBalance) . '.']],
            ], 422);
        }

        $requestedDays = (float) $validated['total_days'];
        if ($requestedDays > $currentBalance) {
            return response()->json([
                'message' => 'Requested monetization days exceed available leave credits.',
                'errors' => ['total_days' => ["Requested ({$requestedDays}) exceeds balance (" . self::formatDays($currentBalance) . ")."]],
            ], 422);
        }

        $equivalentAmount = null;
        $salary = $request->input('salary');
        if ($salary && is_numeric($salary) && (float) $salary > 0) {
            $dailyRate = (float) $salary / 22;
            $equivalentAmount = round($requestedDays * $dailyRate, 2);
        }

        $app = DB::transaction(function () use ($validated, $employee, $admin, $equivalentAmount) {
            $application = LeaveApplication::create([
                'erms_control_no' => (int) $employee->control_no,
                'leave_type_id' => $validated['leave_type_id'],
                'start_date' => null,
                'end_date' => null,
                'total_days' => $validated['total_days'],
                'reason' => $validated['reason'] ?? null,
                'status' => LeaveApplication::STATUS_PENDING_HR,
                'admin_id' => $admin->id,
                'admin_approved_at' => now(),
                'is_monetization' => true,
                'equivalent_amount' => $equivalentAmount,
            ]);

            LeaveApplicationLog::create([
                'leave_application_id' => $application->id,
                'action' => LeaveApplicationLog::ACTION_SUBMITTED,
                'performed_by_type' => LeaveApplicationLog::PERFORMER_ADMIN,
                'performed_by_id' => $admin->id,
                'created_at' => now(),
            ]);

            LeaveApplicationLog::create([
                'leave_application_id' => $application->id,
                'action' => LeaveApplicationLog::ACTION_ADMIN_APPROVED,
                'performed_by_type' => LeaveApplicationLog::PERFORMER_ADMIN,
                'performed_by_id' => $admin->id,
                'remarks' => 'Monetization filed by department admin on behalf of employee.',
                'created_at' => now(),
            ]);

            return $application;
        });

        $app->load('leaveType');
        $employeeName = trim((string) (($employee->firstname ?? '') . ' ' . ($employee->surname ?? '')));
        if ($employeeName === '') {
            $employeeName = $this->resolveEmployeeDisplayName($app);
        }

        $hrAccounts = HRAccount::all();
        foreach ($hrAccounts as $hrAccount) {
            Notification::send(
                $hrAccount,
                Notification::TYPE_LEAVE_REQUEST,
                'Monetization Request Pending HR Review',
                "{$employeeName} filed a {$app->leaveType->name} monetization request (" . self::formatDays($app->total_days) . ") through department admin and it awaits your review.",
                $app->id
            );
        }

        return response()->json([
            'message' => 'Monetization request filed successfully and forwarded to HR.',
            'application' => $this->formatApplication($app->fresh(['leaveType'])),
            'equivalent_amount' => $equivalentAmount,
        ], 201);
    }

    // ═══════════════════════════════════════════════════════════════════
    //  HELPERS
    // ═══════════════════════════════════════════════════════════════════

    /**
     * Calculate max consecutive days from an array of date strings.
     * @deprecated Use LeaveValidationService instead.
     */

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

    private function findEmployeeByControlNo(string $controlNo): ?object
    {
        $controlNo = trim($controlNo);
        if ($controlNo === '') {
            return null;
        }

        return DB::table('tblEmployees')
            ->where('control_no', $controlNo)
            ->orWhereRaw('TRY_CONVERT(INT, control_no) = TRY_CONVERT(INT, ?)', [$controlNo])
            ->first();
    }

    private function ermsStatusLabel(string $status): string
    {
        return match ($status) {
            LeaveApplication::STATUS_PENDING_ADMIN, LeaveApplication::STATUS_PENDING_HR => 'Pending',
            LeaveApplication::STATUS_APPROVED => 'Approved',
            LeaveApplication::STATUS_REJECTED => 'Rejected',
            default => 'Pending',
        };
    }

    private function resolveEmployeeDisplayName(LeaveApplication $app): string
    {
        $name = trim((string) (($app->employee?->firstname ?? '') . ' ' . ($app->employee?->surname ?? '')));
        if ($name !== '') {
            return $name;
        }

        if ($app->erms_control_no !== null) {
            $employee = $this->findEmployeeByControlNo((string) $app->erms_control_no);
            $fallback = trim((string) (($employee->firstname ?? '') . ' ' . ($employee->surname ?? '')));
            if ($fallback !== '') {
                return $fallback;
            }
        }

        return 'An employee';
    }

    private function normalizeControlNo(mixed $value): string
    {
        $normalized = trim((string) ($value ?? ''));
        if ($normalized === '') {
            return '';
        }

        if (preg_match('/^\d+$/', $normalized)) {
            $normalized = ltrim($normalized, '0');
            return $normalized !== '' ? $normalized : '0';
        }

        return $normalized;
    }

    private function formatEmployeeFullName(?object $employee): string
    {
        if (!is_object($employee)) {
            return '';
        }

        return trim(implode(' ', array_filter([
            trim((string) ($employee->firstname ?? $employee->first_name ?? '')),
            trim((string) ($employee->middlename ?? $employee->middle_name ?? '')),
            trim((string) ($employee->surname ?? $employee->last_name ?? '')),
            trim((string) ($employee->suffix ?? '')),
        ], static fn(string $part): bool => $part !== '')));
    }

    private function buildWorkflowActorDirectory(iterable $applications): array
    {
        $adminIds = [];
        $hrIds = [];
        $employeeNamesByControlNo = [];

        foreach ($applications as $application) {
            if (!$application instanceof LeaveApplication) {
                continue;
            }

            if ($application->admin_id) {
                $adminIds[] = (int) $application->admin_id;
            }

            if ($application->hr_id) {
                $hrIds[] = (int) $application->hr_id;
            }

            $employeeControlNo = $this->normalizeControlNo($application->erms_control_no);
            if ($employeeControlNo !== '') {
                $employeeName = $this->formatEmployeeFullName($application->employee);
                if ($employeeName === '') {
                    $employeeName = trim((string) ($application->applicantAdmin?->full_name ?? ''));
                }
                if ($employeeName !== '') {
                    $employeeNamesByControlNo[$employeeControlNo] = $employeeName;
                }
            }

            if (!$application->relationLoaded('logs')) {
                continue;
            }

            foreach ($application->logs as $log) {
                if (!$log instanceof LeaveApplicationLog || !$log->performed_by_id) {
                    continue;
                }

                $performerType = strtoupper((string) $log->performed_by_type);
                if ($performerType === LeaveApplicationLog::PERFORMER_ADMIN) {
                    $adminIds[] = (int) $log->performed_by_id;
                } elseif ($performerType === LeaveApplicationLog::PERFORMER_HR) {
                    $hrIds[] = (int) $log->performed_by_id;
                }
            }
        }

        $adminIds = array_values(array_unique(array_filter($adminIds)));
        $hrIds = array_values(array_unique(array_filter($hrIds)));

        $adminNamesById = DepartmentAdmin::query()
            ->whereIn('id', $adminIds)
            ->pluck('full_name', 'id')
            ->map(fn($name) => trim((string) $name))
            ->all();

        $hrNamesById = HRAccount::query()
            ->whereIn('id', $hrIds)
            ->pluck('full_name', 'id')
            ->map(fn($name) => trim((string) $name))
            ->all();

        return [
            'admin' => $adminNamesById,
            'hr' => $hrNamesById,
            'employee' => $employeeNamesByControlNo,
        ];
    }

    private function resolveWorkflowPerformerName(
        ?LeaveApplicationLog $log,
        array $actorDirectory,
        string $fallbackEmployeeName = ''
    ): ?string {
        if (!$log) {
            return null;
        }

        $performerType = strtoupper((string) $log->performed_by_type);
        $performerId = (int) $log->performed_by_id;
        if ($performerId <= 0) {
            return null;
        }

        if ($performerType === LeaveApplicationLog::PERFORMER_ADMIN) {
            return $actorDirectory['admin'][$performerId] ?? null;
        }

        if ($performerType === LeaveApplicationLog::PERFORMER_HR) {
            return $actorDirectory['hr'][$performerId] ?? null;
        }

        if ($performerType === LeaveApplicationLog::PERFORMER_EMPLOYEE) {
            $controlNo = $this->normalizeControlNo($performerId);
            return $actorDirectory['employee'][$controlNo] ?? ($fallbackEmployeeName !== '' ? $fallbackEmployeeName : null);
        }

        return null;
    }

    private function isCancelledRemark(mixed $remarks): bool
    {
        return (bool) preg_match('/^cancelled\b/i', trim((string) ($remarks ?? '')));
    }

    private function mapWorkflowLogStage(LeaveApplicationLog $log): string
    {
        $remarks = trim((string) ($log->remarks ?? ''));
        $performerType = strtoupper((string) $log->performed_by_type);

        if ($log->action === LeaveApplicationLog::ACTION_SUBMITTED && preg_match('/^edit requested\b/i', $remarks)) {
            return 'employee requested edit';
        }

        if (
            $log->action === LeaveApplicationLog::ACTION_ADMIN_REJECTED
            && $performerType === LeaveApplicationLog::PERFORMER_EMPLOYEE
            && $this->isCancelledRemark($remarks)
        ) {
            return 'employee cancelled';
        }

        return match ($log->action) {
            LeaveApplicationLog::ACTION_SUBMITTED => match ($performerType) {
                LeaveApplicationLog::PERFORMER_ADMIN => 'department admin submitted',
                LeaveApplicationLog::PERFORMER_HR => 'hr submitted',
                default => 'employee submitted',
            },
            LeaveApplicationLog::ACTION_ADMIN_APPROVED => 'department admin approved',
            LeaveApplicationLog::ACTION_ADMIN_REJECTED => 'department admin rejected',
            LeaveApplicationLog::ACTION_HR_APPROVED => 'hr approved',
            LeaveApplicationLog::ACTION_HR_REJECTED => 'hr rejected',
            default => strtolower(str_replace('_', ' ', (string) $log->action)),
        };
    }

    private function formatErmsApplication(LeaveApplication $app, array $actorDirectory): array
    {
        $employeeName = $this->formatEmployeeFullName($app->employee);
        if ($employeeName === '') {
            $employeeName = trim((string) ($app->applicantAdmin?->full_name ?? ''));
        }
        if ($employeeName === '') {
            $employeeName = $this->resolveEmployeeDisplayName($app);
        }

        $logs = $app->relationLoaded('logs')
            ? $app->logs->sortBy(fn(LeaveApplicationLog $log) => $log->created_at?->timestamp ?? 0)->values()
            : collect();

        $submittedLog = $logs->first(
            fn(LeaveApplicationLog $log) => $log->action === LeaveApplicationLog::ACTION_SUBMITTED
        );
        $adminApprovedLog = $logs->first(
            fn(LeaveApplicationLog $log) => $log->action === LeaveApplicationLog::ACTION_ADMIN_APPROVED
        );
        $hrApprovedLog = $logs->first(
            fn(LeaveApplicationLog $log) => $log->action === LeaveApplicationLog::ACTION_HR_APPROVED
        );
        $adminRejectedLog = $logs->first(
            fn(LeaveApplicationLog $log) =>
                $log->action === LeaveApplicationLog::ACTION_ADMIN_REJECTED
                && strtoupper((string) $log->performed_by_type) === LeaveApplicationLog::PERFORMER_ADMIN
        );
        $hrRejectedLog = $logs->first(
            fn(LeaveApplicationLog $log) =>
                $log->action === LeaveApplicationLog::ACTION_HR_REJECTED
                && strtoupper((string) $log->performed_by_type) === LeaveApplicationLog::PERFORMER_HR
        );
        $cancelledLog = $logs->first(
            fn(LeaveApplicationLog $log) =>
                $log->action === LeaveApplicationLog::ACTION_ADMIN_REJECTED
                && strtoupper((string) $log->performed_by_type) === LeaveApplicationLog::PERFORMER_EMPLOYEE
                && $this->isCancelledRemark($log->remarks)
        );

        $filedBy = $this->resolveWorkflowPerformerName($submittedLog, $actorDirectory, $employeeName) ?? $employeeName;
        $adminActionBy = ($app->admin_id && isset($actorDirectory['admin'][(int) $app->admin_id]))
            ? $actorDirectory['admin'][(int) $app->admin_id]
            : $this->resolveWorkflowPerformerName($adminApprovedLog ?? $adminRejectedLog, $actorDirectory, $employeeName);
        $hrActionBy = ($app->hr_id && isset($actorDirectory['hr'][(int) $app->hr_id]))
            ? $actorDirectory['hr'][(int) $app->hr_id]
            : $this->resolveWorkflowPerformerName($hrApprovedLog ?? $hrRejectedLog, $actorDirectory, $employeeName);

        $isCancelled = $this->isCancelledRemark($app->remarks);
        $cancelledBy = $cancelledLog
            ? ($this->resolveWorkflowPerformerName($cancelledLog, $actorDirectory, $employeeName) ?? $employeeName)
            : ($isCancelled ? $employeeName : null);

        $disapprovedBy = null;
        if ($app->status === LeaveApplication::STATUS_REJECTED) {
            if ($cancelledBy) {
                $disapprovedBy = $cancelledBy;
            } elseif ($hrRejectedLog || $app->hr_id) {
                $disapprovedBy = $this->resolveWorkflowPerformerName($hrRejectedLog, $actorDirectory, $employeeName)
                    ?? $hrActionBy;
            } elseif ($adminRejectedLog || $app->admin_id) {
                $disapprovedBy = $this->resolveWorkflowPerformerName($adminRejectedLog, $actorDirectory, $employeeName)
                    ?? $adminActionBy;
            }
        }

        $adminActionAt = $adminApprovedLog?->created_at
            ?? $adminRejectedLog?->created_at
            ?? $app->admin_approved_at;
        $hrActionAt = $hrApprovedLog?->created_at
            ?? $hrRejectedLog?->created_at
            ?? $app->hr_approved_at;
        $cancelledAt = $cancelledLog?->created_at;

        $disapprovedAt = null;
        if ($app->status === LeaveApplication::STATUS_REJECTED) {
            if ($cancelledAt) {
                $disapprovedAt = $cancelledAt;
            } elseif ($hrRejectedLog?->created_at) {
                $disapprovedAt = $hrRejectedLog->created_at;
            } elseif ($adminRejectedLog?->created_at) {
                $disapprovedAt = $adminRejectedLog->created_at;
            }
        }

        $processedBy = null;
        $reviewedAt = null;

        if ($isCancelled) {
            $processedBy = $cancelledBy;
            $reviewedAt = $cancelledAt;
        } elseif ($app->status === LeaveApplication::STATUS_PENDING_HR) {
            $processedBy = $adminActionBy;
            $reviewedAt = $adminActionAt;
        } elseif ($app->status === LeaveApplication::STATUS_APPROVED) {
            $processedBy = $hrActionBy ?? $adminActionBy;
            $reviewedAt = $hrActionAt ?? $adminActionAt;
        } elseif ($app->status === LeaveApplication::STATUS_REJECTED) {
            $processedBy = $disapprovedBy;
            $reviewedAt = $disapprovedAt ?? $hrActionAt ?? $adminActionAt;
        }

        $statusHistory = $logs->map(function (LeaveApplicationLog $log) use ($actorDirectory, $employeeName) {
            $actorName = $this->resolveWorkflowPerformerName($log, $actorDirectory, $employeeName);

            return [
                'action' => $log->action,
                'stage' => $this->mapWorkflowLogStage($log),
                'actor_name' => $actorName,
                'action_by_name' => $actorName,
                'action_by' => $actorName,
                'performed_by_type' => strtoupper((string) $log->performed_by_type),
                'remarks' => $log->remarks,
                'created_at' => $log->created_at?->toIso8601String(),
            ];
        })->values();

        $approverName = $processedBy ?? $hrActionBy ?? $adminActionBy;

        return [
            'id' => $app->id,
            'erms_control_no' => $app->erms_control_no ? (string) $app->erms_control_no : null,
            'control_no' => $app->erms_control_no ? (string) $app->erms_control_no : null,
            'employee_id' => $app->erms_control_no ? (string) $app->erms_control_no : null,
            'leave_type_id' => $app->leave_type_id,
            'leave_type_name' => $app->leaveType?->name,
            'start_date' => $app->start_date?->toDateString(),
            'end_date' => $app->end_date?->toDateString(),
            'total_days' => (float) $app->total_days,
            'date_filed' => $app->created_at?->toDateString(),
            'status' => $this->ermsStatusLabel($app->status),
            'application_status' => $this->ermsStatusLabel($app->status),
            'raw_status' => $app->status,
            'rawStatus' => $app->status,
            'remarks' => $app->remarks,
            'rejection_reason' => $app->status === LeaveApplication::STATUS_REJECTED ? $app->remarks : null,
            'employee_name' => $employeeName,
            'applicant_name' => $employeeName,
            'filed_by' => $filedBy,
            'approver_name' => $approverName,
            'adminActionBy' => $adminActionBy,
            'admin_action_by' => $adminActionBy,
            'admin_name' => $adminActionBy,
            'hrActionBy' => $hrActionBy,
            'hr_action_by' => $hrActionBy,
            'hr_reviewer_name' => $hrActionBy,
            'processedBy' => $processedBy,
            'processed_by' => $processedBy,
            'disapprovedBy' => $disapprovedBy,
            'disapproved_by' => $disapprovedBy,
            'cancelledBy' => $cancelledBy,
            'cancelled_by' => $cancelledBy,
            'reviewedAt' => $reviewedAt?->toIso8601String(),
            'reviewed_at' => $reviewedAt?->toIso8601String(),
            'adminActionAt' => $adminActionAt?->toIso8601String(),
            'admin_action_at' => $adminActionAt?->toIso8601String(),
            'hrActionAt' => $hrActionAt?->toIso8601String(),
            'hr_action_at' => $hrActionAt?->toIso8601String(),
            'disapprovedAt' => $disapprovedAt?->toIso8601String(),
            'disapproved_at' => $disapprovedAt?->toIso8601String(),
            'cancelledAt' => $cancelledAt?->toIso8601String(),
            'cancelled_at' => $cancelledAt?->toIso8601String(),
            'status_history' => $statusHistory,
            'timeline_entries' => $statusHistory,
            'timeline' => $statusHistory,
        ];
    }

    private function validateRegularLeaveEligibility(
        string $employeeControlNo,
        int $leaveTypeId,
        float $requestedDays
    ): array|JsonResponse {
        $leaveType = LeaveType::find($leaveTypeId);
        if (!$leaveType) {
            return response()->json([
                'message' => 'Selected leave type is not available.',
                'errors' => [
                    'leave_type_id' => ['Selected leave type is not available.'],
                ],
            ], 422);
        }

        if ($leaveType->max_days && $requestedDays > (float) $leaveType->max_days) {
            return response()->json([
                'message' => "This leave type is limited to {$leaveType->max_days} days per application.",
                'errors' => [
                    'total_days' => ["Maximum of {$leaveType->max_days} days allowed for {$leaveType->name}."],
                ],
            ], 422);
        }

        if (!$leaveType->is_credit_based) {
            return ['leave_type' => $leaveType, 'balance' => null];
        }

        $balance = LeaveBalance::query()
            ->where('leave_type_id', $leaveTypeId)
            ->where(function ($query) use ($employeeControlNo): void {
                $query->where('employee_id', $employeeControlNo)
                    ->orWhereRaw('TRY_CONVERT(INT, employee_id) = TRY_CONVERT(INT, ?)', [$employeeControlNo]);
            })
            ->first();

        if (!$balance) {
            return response()->json([
                'message' => "{$leaveType->name} is not available for this employee.",
                'errors' => [
                    'leave_type_id' => ["{$leaveType->name} is not available for this employee."],
                ],
            ], 422);
        }

        $currentBalance = (float) $balance->balance;
        if ($currentBalance < $requestedDays) {
            $fmtAvail = self::formatDays($currentBalance);
            $fmtReq = self::formatDays($requestedDays);

            return response()->json([
                'message' => "Insufficient leave balance. You have {$fmtAvail} available but requested {$fmtReq}.",
                'errors' => [
                    'total_days' => ["Insufficient leave balance. Available: {$fmtAvail}."],
                ],
            ], 422);
        }

        return ['leave_type' => $leaveType, 'balance' => $currentBalance];
    }

    private function formatApplication(LeaveApplication $app): array
    {
        $employeeName = $app->employee
            ? trim(($app->employee->firstname ?? '') . ' ' . ($app->employee->surname ?? ''))
            : null;
        $applicantName = $employeeName ?: ($app->applicantAdmin ? $app->applicantAdmin->full_name : 'Unknown');
        $office = $app->employee?->office ?? ($app->applicantAdmin?->department?->name ?? '');

        $data = [
            'id' => $app->id,
            'employee_id' => $app->erms_control_no,
            'applicant_admin_id' => $app->applicant_admin_id,
            'leave_type_id' => $app->leave_type_id,
            'leaveType' => $app->leaveType?->name ?? 'Unknown',
            'startDate' => $app->start_date ? \Carbon\Carbon::parse($app->start_date)->toDateString() : null,
            'endDate' => $app->end_date ? \Carbon\Carbon::parse($app->end_date)->toDateString() : null,
            'days' => (float) $app->total_days,
            'reason' => $app->reason,
            'status' => $this->statusToFrontend($app->status),
            'rawStatus' => $app->status,
            'dateFiled' => $app->created_at ? $app->created_at->toDateString() : '',
            'remarks' => $app->remarks,
            'selected_dates' => $app->selected_dates,
            'commutation' => $app->commutation ?? 'Not Requested',
            'is_monetization' => (bool) $app->is_monetization,
            'equivalent_amount' => $app->equivalent_amount ? (float) $app->equivalent_amount : null,
            'admin_id' => $app->admin_id,
            'hr_id' => $app->hr_id,
            'admin_approved_at' => $app->admin_approved_at?->toIso8601String(),
            'hr_approved_at' => $app->hr_approved_at?->toIso8601String(),
            'applicantName' => $applicantName,
            'office' => $office,
        ];

        if ($app->employee) {
            $data['employee'] = [
                'control_no' => $app->employee->control_no,
                'firstname' => $app->employee->firstname,
                'surname' => $app->employee->surname,
                'full_name' => trim(($app->employee->firstname ?? '') . ' ' . ($app->employee->surname ?? '')),
                'designation' => $app->employee->designation,
                'office' => $app->employee->office,
            ];
        }

        return $data;
    }
}
