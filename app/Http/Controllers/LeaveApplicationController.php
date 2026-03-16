<?php

namespace App\Http\Controllers;

use App\Models\DepartmentAdmin;
use App\Models\Employee;
use App\Models\HRAccount;
use App\Models\LeaveApplication;
use App\Models\LeaveApplicationLog;
use App\Models\LeaveBalance;
use App\Models\LeaveBalanceAccrualHistory;
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

        $employee = Employee::findByControlNo($controlNo);
        if (!$employee) {
            return response()->json(['message' => 'Employee record not found.'], 404);
        }

        $leaveType = LeaveType::find($leaveTypeId);
        if (!$leaveType) {
            return response()->json(['message' => 'Leave type not found.'], 404);
        }

        $leaveTypeBalanceAccess = $this->assertEmployeeCanAccessLeaveTypeBalance($employee, $leaveType);
        if ($leaveTypeBalanceAccess instanceof JsonResponse) {
            return $leaveTypeBalanceAccess;
        }

        $balance = LeaveBalance::query()
            ->with('accrualHistories')
            ->where('employee_id', $employee->control_no)
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
     *
     * Includes accrual metadata so ERMS can show the latest Vacation/Sick leave credits.
     */
    public function ermsGetLeaveBalance(Request $request, int $id): JsonResponse
    {
        $validated = $request->validate([
            'erms_control_no' => ['nullable', 'string', 'regex:/^\d+$/'],
            'leave_type_id' => ['nullable', 'integer', 'exists:tblLeaveTypes,id'],
        ]);

        $queryControlNo = $validated['erms_control_no'] ?? null;
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
                'message' => 'Provide either erms_control_no, or leave_type_id query parameter.',
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

        $leaveTypeBalanceAccess = $this->assertEmployeeCanAccessLeaveTypeBalance($employee, $leaveType);
        if ($leaveTypeBalanceAccess instanceof JsonResponse) {
            return $leaveTypeBalanceAccess;
        }

        $balance = LeaveBalance::query()
            ->with('accrualHistories')
            ->where('employee_id', $employee->control_no)
            ->where('leave_type_id', $leaveTypeId)
            ->first();

        $deductionHistoryByType = $this->loadEmployeeLeaveDeductionHistoryByType((string) $employee->control_no, $leaveTypeId);

        return response()->json(array_merge([
            'erms_control_no' => (string) $employee->control_no,
        ], $this->formatErmsLeaveBalancePayload($leaveType, $balance, $deductionHistoryByType[(int) $leaveTypeId] ?? [])));
    }

    /**
     * GET /erms/leave-balances/{controlNo}
     * API-key protected endpoint for loading all leave balances in one request.
     * Also returns the latest Vacation/Sick accrued credits for ERMS leave cards.
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

        $types = $this->getAllowedErmsLeaveTypesQuery($employee)
            ->select(['id', 'name', 'category', 'accrual_rate', 'accrual_day_of_month', 'is_credit_based'])
            ->orderBy('name')
            ->get();

        $typesByName = $types
            ->keyBy(fn(LeaveType $type) => strtolower(trim((string) $type->name)))
            ->all();

        $balanceRecordsByType = LeaveBalance::query()
            ->with('accrualHistories')
            ->where('employee_id', $employee->control_no)
            ->get()
            ->keyBy(fn(LeaveBalance $balance) => (int) $balance->leave_type_id)
            ->all();
        $deductionHistoryByType = $this->loadEmployeeLeaveDeductionHistoryByType((string) $employee->control_no);

        $balances = $types->map(function (LeaveType $type) use ($balanceRecordsByType, $deductionHistoryByType) {
            $balance = $balanceRecordsByType[(int) $type->id] ?? null;
            return $this->formatErmsLeaveBalancePayload(
                $type,
                $balance instanceof LeaveBalance ? $balance : null,
                $deductionHistoryByType[(int) $type->id] ?? []
            );
        })->values();

        return response()->json([
            'erms_control_no' => (string) $employee->control_no,
            'balances' => $balances,
            'latest_accrued_credits' => $this->buildErmsLatestAccruedCreditsPayload(
                $employee,
                $typesByName,
                $balanceRecordsByType,
                $deductionHistoryByType
            ),
        ]);
    }

    /**
     * GET /erms/apply-leave
     * API-key protected endpoint for ERMS/HRPDS personal leave records listing.
     */
    public function ermsIndex(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'erms_control_no' => ['nullable', 'string', 'regex:/^\d+$/'],
        ]);

        $controlNo = trim((string) ($validated['erms_control_no'] ?? ''));
        if ($controlNo === '') {
            return response()->json([
                'message' => 'The erms_control_no query parameter is required.',
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

        $employeeContext = $this->formatErmsEmployeeContext($employee);
        $leaveTypes = $this->getAllowedErmsLeaveTypesForEmployee($employee);

        return response()->json([
            'erms_control_no' => (string) $employee->control_no,
            'employment_status' => $employeeContext['status'],
            'employment_status_key' => $employeeContext['employment_status_key'],
            'ui_variant' => $employeeContext['ui_variant'],
            'employee' => $employeeContext,
            'leave_types' => $leaveTypes,
            'applications' => $applications
                ->map(fn(LeaveApplication $app) => $this->formatErmsApplication($app, $actorDirectory))
                ->values(),
        ]);
    }

    /**
     * POST /erms/apply-leave
     * API-key protected endpoint for ERMS-to-LMS leave application submission.
     */
    public function ermsStore(Request $request): JsonResponse
    {
        $this->normalizeSelectedDatesInput($request);

        $baseValidated = $request->validate([
            'erms_control_no' => ['required', 'string', 'regex:/^\d+$/'],
            'is_monetization' => ['nullable', 'boolean'],
        ]);

        $controlNo = trim((string) $baseValidated['erms_control_no']);

        $employee = $this->findEmployeeByControlNo($controlNo);

        if (!$employee) {
            return response()->json(['message' => 'Employee record not found.'], 404);
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

        $duplicateDateValidation = $this->validateNoDuplicateLeaveDates(
            (string) $employee->control_no,
            (string) $validated['start_date'],
            (string) $validated['end_date'],
            $validated['selected_dates'] ?? null,
            $validated['total_days']
        );
        if ($duplicateDateValidation instanceof JsonResponse) {
            return $duplicateDateValidation;
        }

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
     * POST /erms/leave-applications/{id}/cancel
     *
     * API-key protected endpoint for ERMS-to-LMS leave cancellation.
     * Cancels only pending applications owned by the provided employee.
     */
    public function ermsCancel(Request $request, ?int $id = null): JsonResponse
    {
        $routeId = $id;

        $request->merge(array_filter([
            'leave_application_id' => $request->input('leave_application_id')
                ?? $routeId,
        ], static fn($value) => $value !== null && $value !== ''));

        $validated = $request->validate([
            'leave_application_id' => ['required', 'integer'],
            'erms_control_no' => ['required', 'string', 'regex:/^\d+$/'],
            'cancellation_reason' => ['nullable', 'string', 'max:2000'],
        ]);

        $applicationId = (int) $validated['leave_application_id'];
        if ($routeId !== null && $applicationId !== $routeId) {
            return response()->json([
                'message' => 'Route ID and payload leave_application_id must match.',
            ], 422);
        }

        $controlNo = trim((string) $validated['erms_control_no']);
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

        $application = $app->fresh(['leaveType', 'employee', 'applicantAdmin.department', 'logs']);
        $actorDirectory = $this->buildWorkflowActorDirectory([$application]);

        return response()->json([
            'message' => 'Leave application cancelled successfully.',
            'application' => $this->formatErmsApplication($application, $actorDirectory),
        ]);
    }

    /**
     * POST /erms/leave-applications/{id}/request-edit
     *
     * API-key protected endpoint for ERMS-to-LMS leave edit request.
     * Accepts reason/remarks and notifies admin/HR for pending applications.
     */
    public function ermsRequestEdit(Request $request, ?int $id = null): JsonResponse
    {
        $routeId = $id;

        $request->merge(array_filter([
            'leave_application_id' => $request->input('leave_application_id')
                ?? $routeId,
        ], static fn($value) => $value !== null && $value !== ''));

        $validated = $request->validate([
            'leave_application_id' => ['required', 'integer'],
            'erms_control_no' => ['required', 'string', 'regex:/^\d+$/'],
            'edit_reason' => ['nullable', 'string', 'max:2000'],
        ]);

        $applicationId = (int) $validated['leave_application_id'];
        if ($routeId !== null && $applicationId !== $routeId) {
            return response()->json([
                'message' => 'Route ID and payload leave_application_id must match.',
            ], 422);
        }

        $controlNo = trim((string) $validated['erms_control_no']);
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
        $this->normalizeSelectedDatesInput($request);

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

        $duplicateDateValidation = $this->validateNoDuplicateLeaveDates(
            (string) $employee->control_no,
            (string) $validated['start_date'],
            (string) $validated['end_date'],
            $validated['selected_dates'] ?? null,
            $validated['total_days']
        );
        if ($duplicateDateValidation instanceof JsonResponse) {
            return $duplicateDateValidation;
        }

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
        $leaveTypeRestriction = $leaveType
            ? $this->assertEmployeeCanApplyForLeaveType($employee, $leaveType)
            : null;
        if ($leaveTypeRestriction instanceof JsonResponse) {
            return $leaveTypeRestriction;
        }

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
        $applications = LeaveApplication::with(['leaveType', 'employee', 'applicantAdmin.department'])
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

        $applications = LeaveApplication::with(['leaveType', 'employee', 'applicantAdmin.department'])
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

        $forcedLeaveTypeId = $this->resolveForcedLeaveTypeId();
        $shouldDeductForcedLeave = $this->shouldDeductForcedLeaveWithVacation($app, $forcedLeaveTypeId);

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

        $balanceConflictError = 'HR_APPROVAL_BALANCE_CONFLICT';

        try {
            DB::transaction(function () use ($app, $hr, $request, $needsDeduction, $balanceConflictError, $forcedLeaveTypeId, $shouldDeductForcedLeave) {
                // Deduct balance for credit-based leave types and monetization.
                // Locking the exact target row avoids race conditions and cross-row side effects.
                if ($needsDeduction) {
                    if ($app->erms_control_no) {
                        $lockedBalance = LeaveBalance::query()
                            ->where('employee_id', $app->erms_control_no)
                            ->where('leave_type_id', $app->leave_type_id)
                            ->lockForUpdate()
                            ->first();

                        if (!$lockedBalance || (float) $lockedBalance->balance < (float) $app->total_days) {
                            throw new \RuntimeException($balanceConflictError);
                        }

                        $lockedBalance->decrement('balance', (float) $app->total_days);

                        // Business rule: approving Vacation Leave also consumes Forced Leave credits.
                        // If Forced Leave is zero/missing, no extra deduction is applied.
                        if ($shouldDeductForcedLeave && $forcedLeaveTypeId !== null) {
                            $forcedBalance = LeaveBalance::query()
                                ->where('employee_id', $app->erms_control_no)
                                ->where('leave_type_id', $forcedLeaveTypeId)
                                ->lockForUpdate()
                                ->first();

                            $forcedAvailable = $forcedBalance ? (float) $forcedBalance->balance : 0.0;
                            $forcedToDeduct = min((float) $app->total_days, max($forcedAvailable, 0.0));

                            if ($forcedBalance && $forcedToDeduct > 0.0) {
                                $forcedBalance->decrement('balance', $forcedToDeduct);
                            }
                        }
                    } elseif ($app->applicant_admin_id) {
                        $lockedBalance = \App\Models\AdminLeaveBalance::query()
                            ->where('admin_id', $app->applicant_admin_id)
                            ->where('leave_type_id', $app->leave_type_id)
                            ->where('year', now()->year)
                            ->lockForUpdate()
                            ->first();

                        if (!$lockedBalance || (float) $lockedBalance->balance < (float) $app->total_days) {
                            throw new \RuntimeException($balanceConflictError);
                        }

                        $lockedBalance->decrement('balance', (float) $app->total_days);
                    }
                }

                $app->update([
                    'status' => LeaveApplication::STATUS_APPROVED,
                    'hr_id' => $hr->id,
                    'hr_approved_at' => now(),
                    'remarks' => $request->input('remarks'),
                ]);

                LeaveApplicationLog::create([
                    'leave_application_id' => $app->id,
                    'action' => LeaveApplicationLog::ACTION_HR_APPROVED,
                    'performed_by_type' => LeaveApplicationLog::PERFORMER_HR,
                    'performed_by_id' => $hr->id,
                    'remarks' => $request->input('remarks'),
                    'created_at' => now(),
                ]);
            });
        } catch (\RuntimeException $exception) {
            if ($exception->getMessage() !== $balanceConflictError) {
                throw $exception;
            }

            if ($app->erms_control_no) {
                $currentBalance = (float) (LeaveBalance::query()
                    ->where('employee_id', $app->erms_control_no)
                    ->where('leave_type_id', $app->leave_type_id)
                    ->value('balance') ?? 0);

                if ($app->is_monetization && $currentBalance < 10) {
                    return response()->json([
                        'message' => 'Minimum of 10 leave credits required for monetization.',
                    ], 422);
                }

                $label = $app->is_monetization ? 'monetization' : 'leave';
                return response()->json([
                    'message' => "Insufficient leave balance for {$label}. Current: " . self::formatDays($currentBalance) . ", Requested: " . self::formatDays($app->total_days) . ".",
                ], 422);
            }

            $currentBalance = (float) (\App\Models\AdminLeaveBalance::query()
                ->where('admin_id', $app->applicant_admin_id)
                ->where('leave_type_id', $app->leave_type_id)
                ->where('year', now()->year)
                ->value('balance') ?? 0);

            return response()->json([
                'message' => "Insufficient leave balance. Current: " . self::formatDays($currentBalance) . ", Requested: " . self::formatDays($app->total_days) . ".",
            ], 422);
        }

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
        $this->normalizeSelectedDatesInput($request);

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
        $employee = Employee::findByControlNo($validated['employee_id']);
        if (!$employee || $employee->office !== $admin->department?->name) {
            return response()->json(['message' => 'You can only file leave for employees in your department.'], 403);
        }

        $duplicateDateValidation = $this->validateNoDuplicateLeaveDates(
            (string) $employee->control_no,
            (string) $validated['start_date'],
            (string) $validated['end_date'],
            $validated['selected_dates'] ?? null,
            $validated['total_days']
        );
        if ($duplicateDateValidation instanceof JsonResponse) {
            return $duplicateDateValidation;
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
        $employee = Employee::findByControlNo($validated['employee_id']);
        if (!$employee || $employee->office !== $admin->department?->name) {
            return response()->json(['message' => 'You can only file for employees in your department.'], 403);
        }

        $leaveType = LeaveType::find($validated['leave_type_id']);
        $leaveTypeRestriction = $leaveType
            ? $this->assertEmployeeCanApplyForLeaveType($employee, $leaveType)
            : null;
        if ($leaveTypeRestriction instanceof JsonResponse) {
            return $leaveTypeRestriction;
        }

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

        return Employee::findByControlNo($controlNo);
    }

    private function formatErmsEmployeeContext(object $employee): array
    {
        $statusKey = $this->resolveEmploymentStatusKey($employee->status ?? null);

        return [
            'control_no' => (string) ($employee->control_no ?? ''),
            'firstname' => $employee->firstname ?? null,
            'surname' => $employee->surname ?? null,
            'middlename' => $employee->middlename ?? null,
            'office' => $employee->office ?? null,
            'designation' => $employee->designation ?? null,
            'status' => $this->formatEmploymentStatusLabel($employee->status ?? null),
            'raw_status' => $this->trimNullableString($employee->status ?? null),
            'employment_status_key' => $statusKey,
            'ui_variant' => $statusKey === 'contractual' ? 'contractual' : 'default',
            'allowed_leave_scope' => $statusKey === 'contractual' ? 'wellness_only' : 'all',
            'is_contractual' => $statusKey === 'contractual',
        ];
    }

    private function getAllowedErmsLeaveTypesQuery(object $employee)
    {
        return LeaveType::query()
            ->when($this->isContractualEmployee($employee), function ($query): void {
                $query->whereRaw('LOWER(LTRIM(RTRIM(name))) = ?', ['wellness leave']);
            });
    }

    private function getAllowedErmsLeaveTypesForEmployee(object $employee)
    {
        return $this->getAllowedErmsLeaveTypesQuery($employee)
            ->orderBy('name')
            ->get(['id', 'name', 'category', 'max_days', 'is_credit_based']);
    }

    private function assertEmployeeCanApplyForLeaveType(object $employee, LeaveType $leaveType): ?JsonResponse
    {
        return $this->assertEmployeeCanUseWellnessOnlyRule(
            $employee,
            $leaveType,
            'Contractual employees can only apply for Wellness Leave.'
        );
    }

    private function assertEmployeeCanAccessLeaveTypeBalance(object $employee, LeaveType $leaveType): ?JsonResponse
    {
        return $this->assertEmployeeCanUseWellnessOnlyRule(
            $employee,
            $leaveType,
            'Contractual employees can only access Wellness Leave balances.'
        );
    }

    private function assertEmployeeCanUseWellnessOnlyRule(
        object $employee,
        LeaveType $leaveType,
        string $message
    ): ?JsonResponse {
        if (!$this->isContractualEmployee($employee) || $this->isWellnessLeaveType($leaveType)) {
            return null;
        }

        return response()->json([
            'message' => $message,
            'errors' => [
                'leave_type_id' => [$message],
            ],
        ], 422);
    }

    private function isContractualEmployee(object $employee): bool
    {
        return $this->resolveEmploymentStatusKey($employee->status ?? null) === 'contractual';
    }

    private function isWellnessLeaveType(LeaveType $leaveType): bool
    {
        return strtolower(trim((string) $leaveType->name)) === 'wellness leave';
    }

    private function resolveEmploymentStatusKey(?string $status): ?string
    {
        $normalizedStatus = strtoupper(trim((string) ($status ?? '')));

        return match ($normalizedStatus) {
            '' => null,
            'REGULAR' => 'regular',
            'ELECTIVE' => 'elective',
            'CO-TERMINOUS', 'CO TERMINOUS', 'COTERMINOUS' => 'co_terminous',
            'CASUAL' => 'casual',
            'CONTRACTUAL' => 'contractual',
            default => strtolower(str_replace([' ', '-'], '_', $normalizedStatus)),
        };
    }

    private function formatEmploymentStatusLabel(?string $status): ?string
    {
        $statusKey = $this->resolveEmploymentStatusKey($status);

        return match ($statusKey) {
            null => null,
            'regular' => 'Regular',
            'elective' => 'Elective',
            'co_terminous' => 'Co-Terminous',
            'casual' => 'Casual',
            'contractual' => 'Contractual',
            default => $this->trimNullableString($status),
        };
    }

    private function trimNullableString(mixed $value): ?string
    {
        $trimmed = trim((string) ($value ?? ''));
        return $trimmed === '' ? null : $trimmed;
    }

    private function formatErmsLeaveBalancePayload(
        LeaveType $leaveType,
        ?LeaveBalance $balance,
        array $deductionHistory = []
    ): array
    {
        $accrualHistory = [];
        if ($balance) {
            $historyEntries = $balance->relationLoaded('accrualHistories')
                ? $balance->accrualHistories
                : $balance->accrualHistories()->get();

            $accrualHistory = $historyEntries
                ->map(function (LeaveBalanceAccrualHistory $entry): array {
                    return [
                        'accrual_date' => $entry->accrual_date?->toDateString(),
                        'transaction_date' => $entry->accrual_date?->toDateString(),
                        'credits_added' => (float) $entry->credits_added,
                        'entry_type' => 'ACCRUAL',
                        'transaction_type' => 'ACCRUAL',
                        'label' => 'Monthly accrual',
                        'description' => 'Monthly accrual',
                        'source' => $entry->source,
                        'created_at' => $entry->created_at?->toIso8601String(),
                    ];
                })
                ->values()
                ->all();
        }

        $creditHistory = array_merge($accrualHistory, $deductionHistory);
        usort($creditHistory, function (array $left, array $right): int {
            $leftDate = (string) ($left['transaction_date'] ?? $left['accrual_date'] ?? $left['created_at'] ?? '');
            $rightDate = (string) ($right['transaction_date'] ?? $right['accrual_date'] ?? $right['created_at'] ?? '');
            if ($leftDate !== $rightDate) {
                return $leftDate < $rightDate ? 1 : -1;
            }

            $leftCreatedAt = (string) ($left['created_at'] ?? '');
            $rightCreatedAt = (string) ($right['created_at'] ?? '');
            if ($leftCreatedAt !== $rightCreatedAt) {
                return $leftCreatedAt < $rightCreatedAt ? 1 : -1;
            }

            return ((int) ($right['leave_application_id'] ?? 0)) <=> ((int) ($left['leave_application_id'] ?? 0));
        });

        return [
            'leave_type_id' => (int) $leaveType->id,
            'leave_type_name' => $leaveType->name,
            'balance' => $balance ? (float) $balance->balance : 0.0,
            'is_credit_based' => (bool) $leaveType->is_credit_based,
            'is_accrued' => $leaveType->category === LeaveType::CATEGORY_ACCRUED,
            'accrual_rate' => $leaveType->accrual_rate !== null ? (float) $leaveType->accrual_rate : null,
            'accrual_day_of_month' => $leaveType->accrual_day_of_month !== null ? (int) $leaveType->accrual_day_of_month : null,
            'last_accrual_date' => $balance?->last_accrual_date?->toDateString(),
            'accrual_history' => $accrualHistory,
            'accrualHistory' => $accrualHistory,
            'credit_history' => $creditHistory,
            'creditHistory' => $creditHistory,
            'updated_at' => $balance?->updated_at?->toIso8601String(),
            'year' => $balance?->year !== null ? (int) $balance->year : null,
        ];
    }

    private function buildErmsAccruedLeaveCard(
        array $typesByName,
        array $balanceRecordsByType,
        array $deductionHistoryByType,
        string $leaveTypeName
    ): array
    {
        $type = $typesByName[strtolower(trim($leaveTypeName))] ?? null;
        if (!$type instanceof LeaveType) {
            return $this->emptyErmsLeaveBalancePayload($leaveTypeName);
        }

        $balance = $balanceRecordsByType[(int) $type->id] ?? null;

        return $this->formatErmsLeaveBalancePayload(
            $type,
            $balance instanceof LeaveBalance ? $balance : null,
            $deductionHistoryByType[(int) $type->id] ?? []
        );
    }

    private function buildErmsLatestAccruedCreditsPayload(
        object $employee,
        array $typesByName,
        array $balanceRecordsByType,
        array $deductionHistoryByType
    ): array {
        if ($this->isContractualEmployee($employee)) {
            return [
                'vacation' => $this->emptyErmsLeaveBalancePayload('Vacation Leave'),
                'sick' => $this->emptyErmsLeaveBalancePayload('Sick Leave'),
                'wellness' => $this->buildErmsAccruedLeaveCard(
                    $typesByName,
                    $balanceRecordsByType,
                    $deductionHistoryByType,
                    'Wellness Leave'
                ),
            ];
        }

        return [
            'vacation' => $this->buildErmsAccruedLeaveCard(
                $typesByName,
                $balanceRecordsByType,
                $deductionHistoryByType,
                'Vacation Leave'
            ),
            'sick' => $this->buildErmsAccruedLeaveCard(
                $typesByName,
                $balanceRecordsByType,
                $deductionHistoryByType,
                'Sick Leave'
            ),
        ];
    }

    private function emptyErmsLeaveBalancePayload(string $leaveTypeName): array
    {
        return [
            'leave_type_id' => null,
            'leave_type_name' => $leaveTypeName,
            'balance' => 0.0,
            'is_credit_based' => null,
            'is_accrued' => null,
            'accrual_rate' => null,
            'accrual_day_of_month' => null,
            'last_accrual_date' => null,
            'accrual_history' => [],
            'accrualHistory' => [],
            'credit_history' => [],
            'creditHistory' => [],
            'updated_at' => null,
            'year' => null,
        ];
    }

    private function loadEmployeeLeaveDeductionHistoryByType(string $controlNo, ?int $leaveTypeId = null): array
    {
        $applications = LeaveApplication::query()
            ->with(['leaveType:id,name,is_credit_based'])
            ->where('status', LeaveApplication::STATUS_APPROVED)
            ->where(function ($query) use ($controlNo): void {
                $query->where('erms_control_no', $controlNo)
                    ->orWhereRaw('TRY_CONVERT(INT, erms_control_no) = TRY_CONVERT(INT, ?)', [$controlNo]);
            })
            ->when($leaveTypeId !== null, function ($query) use ($leaveTypeId): void {
                $query->where('leave_type_id', $leaveTypeId);
            })
            ->orderByDesc('hr_approved_at')
            ->orderByDesc('id')
            ->get([
                'id',
                'erms_control_no',
                'leave_type_id',
                'total_days',
                'is_monetization',
                'status',
                'hr_approved_at',
                'created_at',
            ]);

        $historyByType = [];

        foreach ($applications as $application) {
            if (!$application instanceof LeaveApplication) {
                continue;
            }

            $typeId = (int) $application->leave_type_id;
            if ($typeId <= 0) {
                continue;
            }

            $deductsEmployeeBalance = (bool) $application->is_monetization
                || (bool) ($application->leaveType?->is_credit_based);
            if (!$deductsEmployeeBalance) {
                continue;
            }

            $approvedAt = $application->hr_approved_at ?? $application->created_at;
            $creditsDeducted = round((float) $application->total_days, 2);
            if ($approvedAt === null || $creditsDeducted <= 0) {
                continue;
            }

            $historyByType[$typeId][] = [
                'transaction_date' => $approvedAt->toDateString(),
                'credits_added' => -$creditsDeducted,
                'entry_type' => 'DEDUCTION',
                'transaction_type' => 'DEDUCTION',
                'label' => $application->is_monetization ? 'Monetization approved' : 'Leave approved',
                'description' => $application->is_monetization
                    ? 'Approved monetization'
                    : 'Approved leave application',
                'leave_application_id' => (int) $application->id,
                'source' => 'LEAVE_APPLICATION',
                'created_at' => $approvedAt->toIso8601String(),
            ];
        }

        return $historyByType;
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

    private function extractCancellationReason(mixed $remarks): ?string
    {
        $normalizedRemarks = trim((string) ($remarks ?? ''));
        if ($normalizedRemarks === '' || !$this->isCancelledRemark($normalizedRemarks)) {
            return null;
        }

        if (preg_match('/^cancelled(?:\s+via\s+[a-z0-9 _-]+)?\s*:\s*(.+)$/i', $normalizedRemarks, $matches)) {
            $reason = trim((string) ($matches[1] ?? ''));
            return $reason !== '' ? $reason : null;
        }

        return null;
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

        $isCancelled = $cancelledLog !== null || $this->isCancelledRemark($app->remarks);
        $displayStatus = $isCancelled ? 'Cancelled' : $this->ermsStatusLabel($app->status);
        $cancellationReason = $this->extractCancellationReason($app->remarks);
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
        $cancelledAt = $cancelledLog?->created_at ?? ($isCancelled ? $app->updated_at : null);

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

        $selectedDates = $app->resolvedSelectedDates();

        return [
            'id' => $app->id,
            'erms_control_no' => $app->erms_control_no ? (string) $app->erms_control_no : null,
            'leave_type_id' => $app->leave_type_id,
            'leave_type_name' => $app->leaveType?->name,
            'start_date' => $app->start_date?->toDateString(),
            'end_date' => $app->end_date?->toDateString(),
            'selected_dates' => $selectedDates,
            'total_days' => (float) $app->total_days,
            'date_filed' => $app->created_at?->toDateString(),
            'filed_at' => $app->created_at?->toIso8601String(),
            'created_at' => $app->created_at?->toIso8601String(),
            'status' => $displayStatus,
            'raw_status' => $app->status,
            'remarks' => $app->remarks,
            'rejection_reason' => $app->status === LeaveApplication::STATUS_REJECTED && !$isCancelled ? $app->remarks : null,
            'employee_name' => $employeeName,
            'filed_by' => $filedBy,
            'approver_name' => $approverName,
            'admin_action_by' => $adminActionBy,
            'hr_action_by' => $hrActionBy,
            'processed_by' => $processedBy,
            'disapproved_by' => $disapprovedBy,
            'cancelled_by' => $cancelledBy,
            'cancelled' => $isCancelled,
            'cancellation_reason' => $cancellationReason,
            'reviewed_at' => $reviewedAt?->toIso8601String(),
            'admin_action_at' => $adminActionAt?->toIso8601String(),
            'hr_action_at' => $hrActionAt?->toIso8601String(),
            'disapproved_at' => $disapprovedAt?->toIso8601String(),
            'cancelled_at' => $cancelledAt?->toIso8601String(),
            'status_history' => $statusHistory,
        ];
    }

    private function resolveForcedLeaveTypeId(): ?int
    {
        $value = LeaveType::query()
            ->whereRaw('LOWER(name) = ?', ['mandatory / forced leave'])
            ->value('id');

        return $value !== null ? (int) $value : null;
    }

    private function shouldDeductForcedLeaveWithVacation(LeaveApplication $app, ?int $forcedLeaveTypeId): bool
    {
        if ($forcedLeaveTypeId === null) {
            return false;
        }

        if ($app->is_monetization) {
            return false;
        }

        if ((int) $app->leave_type_id === $forcedLeaveTypeId) {
            return false;
        }

        $leaveTypeName = trim((string) ($app->leaveType?->name ?? ''));
        return strcasecmp($leaveTypeName, 'Vacation Leave') === 0;
    }

    private function validateNoDuplicateLeaveDates(
        string $employeeControlNo,
        string $startDate,
        string $endDate,
        ?array $selectedDates = null,
        mixed $totalDays = null
    ): ?JsonResponse {
        $requestedDates = $this->resolveLeaveDateSet($startDate, $endDate, $selectedDates, $totalDays);
        if ($requestedDates === []) {
            return null;
        }

        $existingApplications = LeaveApplication::query()
            ->select(['id', 'start_date', 'end_date', 'selected_dates', 'total_days'])
            ->whereIn('status', [
                LeaveApplication::STATUS_PENDING_ADMIN,
                LeaveApplication::STATUS_PENDING_HR,
                LeaveApplication::STATUS_APPROVED,
            ])
            ->where(function ($query) use ($employeeControlNo): void {
                $query->where('erms_control_no', $employeeControlNo)
                    ->orWhereRaw('TRY_CONVERT(INT, erms_control_no) = TRY_CONVERT(INT, ?)', [$employeeControlNo]);
            })
            ->get();

        $duplicateDateMap = [];
        foreach ($existingApplications as $existingApplication) {
            $existingDates = $this->resolveLeaveDateSet(
                $existingApplication->start_date?->toDateString(),
                $existingApplication->end_date?->toDateString(),
                is_array($existingApplication->selected_dates) ? $existingApplication->selected_dates : null,
                $existingApplication->total_days
            );

            foreach (array_intersect($requestedDates, $existingDates) as $duplicateDate) {
                $duplicateDateMap[$duplicateDate] = true;
            }
        }

        if ($duplicateDateMap === []) {
            return null;
        }

        $duplicateDates = array_keys($duplicateDateMap);
        sort($duplicateDates);

        $previewDates = array_slice($duplicateDates, 0, 3);
        $formattedPreview = implode(', ', array_map(
            static fn(string $date): string => \Carbon\CarbonImmutable::parse($date)->format('M j, Y'),
            $previewDates
        ));
        if (count($duplicateDates) > count($previewDates)) {
            $remainingCount = count($duplicateDates) - count($previewDates);
            $formattedPreview .= " and {$remainingCount} more";
        }

        return response()->json([
            'message' => "Duplicate leave date detected. You already have a leave application for {$formattedPreview}.",
            'errors' => [
                'selected_dates' => ['Duplicate leave dates are not allowed for the same employee.'],
            ],
            'duplicate_dates' => $duplicateDates,
        ], 422);
    }

    private function resolveLeaveDateSet(
        ?string $startDate,
        ?string $endDate,
        ?array $selectedDates = null,
        mixed $totalDays = null
    ): array
    {
        return LeaveApplication::resolveDateSet($startDate, $endDate, $selectedDates, $totalDays);
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

        $employee = $this->findEmployeeByControlNo($employeeControlNo);
        if ($employee) {
            $leaveTypeRestriction = $this->assertEmployeeCanApplyForLeaveType($employee, $leaveType);
            if ($leaveTypeRestriction instanceof JsonResponse) {
                return $leaveTypeRestriction;
            }
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
        $pendingReservedDays = (float) LeaveApplication::query()
            ->where('leave_type_id', $leaveTypeId)
            ->whereIn('status', [
                LeaveApplication::STATUS_PENDING_ADMIN,
                LeaveApplication::STATUS_PENDING_HR,
            ])
            ->where(function ($query) use ($employeeControlNo): void {
                $query->where('erms_control_no', $employeeControlNo)
                    ->orWhereRaw('TRY_CONVERT(INT, erms_control_no) = TRY_CONVERT(INT, ?)', [$employeeControlNo]);
            })
            ->sum('total_days');

        $availableBalance = max(round($currentBalance - $pendingReservedDays, 2), 0.0);

        if ($availableBalance < $requestedDays) {
            $fmtAvail = self::formatDays($availableBalance);
            $fmtReq = self::formatDays($requestedDays);
            $fmtCurrent = self::formatDays($currentBalance);
            $fmtPending = self::formatDays($pendingReservedDays);

            return response()->json([
                'message' => "Insufficient leave balance. You have {$fmtAvail} available (current {$fmtCurrent}, pending {$fmtPending}) but requested {$fmtReq}.",
                'errors' => [
                    'total_days' => ["Insufficient leave balance. Available: {$fmtAvail}."],
                ],
            ], 422);
        }

        return [
            'leave_type' => $leaveType,
            'balance' => $currentBalance,
            'available_balance' => $availableBalance,
            'pending_reserved_days' => $pendingReservedDays,
        ];
    }

    private function formatApplication(LeaveApplication $app): array
    {
        $employeeName = $this->formatEmployeeFullName($app->employee);
        $applicantName = $employeeName !== ''
            ? $employeeName
            : trim((string) ($app->applicantAdmin?->full_name ?? ''));
        if ($applicantName === '') {
            $applicantName = $this->resolveEmployeeDisplayName($app);
        }
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
            'filedAt' => $app->created_at?->toIso8601String(),
            'filed_at' => $app->created_at?->toIso8601String(),
            'createdAt' => $app->created_at?->toIso8601String(),
            'created_at' => $app->created_at?->toIso8601String(),
            'remarks' => $app->remarks,
            'selected_dates' => $app->resolvedSelectedDates(),
            'commutation' => $app->commutation ?? 'Not Requested',
            'is_monetization' => (bool) $app->is_monetization,
            'equivalent_amount' => $app->equivalent_amount ? (float) $app->equivalent_amount : null,
            'admin_id' => $app->admin_id,
            'hr_id' => $app->hr_id,
            'admin_approved_at' => $app->admin_approved_at?->toIso8601String(),
            'hr_approved_at' => $app->hr_approved_at?->toIso8601String(),
            'employeeName' => $applicantName,
            'employee_name' => $applicantName,
            'applicantName' => $applicantName,
            'applicant_name' => $applicantName,
            'office' => $office,
        ];

        if ($app->employee) {
            $data['employee'] = [
                'control_no' => $app->employee->control_no,
                'firstname' => $app->employee->firstname,
                'middlename' => $app->employee->middlename,
                'surname' => $app->employee->surname,
                'full_name' => $this->formatEmployeeFullName($app->employee),
                'designation' => $app->employee->designation,
                'office' => $app->employee->office,
            ];
        }

        return $data;
    }

    private function normalizeSelectedDatesInput(Request $request): void
    {
        $selectedDates = $request->input('selected_dates');
        if (is_string($selectedDates)) {
            $decoded = json_decode($selectedDates, true);
            if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
                $selectedDates = $decoded;
            }
        }

        if ($selectedDates !== null && $selectedDates !== '') {
            $request->merge(['selected_dates' => $selectedDates]);
        }
    }
}