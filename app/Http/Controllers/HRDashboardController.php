<?php

namespace App\Http\Controllers;

use App\Models\DepartmentAdmin;
use App\Models\Employee;
use App\Models\HRAccount;
use App\Models\LeaveApplication;
use App\Models\LeaveApplicationUpdateRequest;
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

        $applications = LeaveApplication::with(['leaveType', 'employee', 'applicantAdmin.department', 'updateRequests'])
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
            LeaveApplication::STATUS_RECALLED => 'Recalled',
        ];

        // Determine applicant name & office
        $employeeName = trim((string) ($app->employee_name ?? ''));
        if ($employeeName === '') {
            $employeeName = $app->employee
                ? trim(($app->employee->firstname ?? '') . ' ' . ($app->employee->surname ?? ''))
                : null;
        }
        $applicantName = $employeeName ?: ($app->applicantAdmin ? $app->applicantAdmin->full_name : 'Unknown');
        $office = $app->employee?->office ?? ($app->applicantAdmin?->department?->name ?? '');
        $durationDays = (float) $app->total_days;
        $pendingUpdateMeta = $this->resolvePendingUpdateMeta($app);
        $latestUpdateMeta = $this->resolveLatestUpdateMeta($app);
        $selectedDatePayStatus = is_array($app->selected_date_pay_status) ? $app->selected_date_pay_status : null;
        $selectedDateCoverage = is_array($app->selected_date_coverage) ? $app->selected_date_coverage : null;
        $normalizedPayMode = strtoupper(trim((string) ($app->pay_mode ?? LeaveApplication::PAY_MODE_WITH_PAY)));
        if (!in_array($normalizedPayMode, [LeaveApplication::PAY_MODE_WITH_PAY, LeaveApplication::PAY_MODE_WITHOUT_PAY], true)) {
            $normalizedPayMode = LeaveApplication::PAY_MODE_WITH_PAY;
        }
        $deductibleDays = $app->deductible_days !== null
            ? round((float) $app->deductible_days, 2)
            : ($normalizedPayMode === LeaveApplication::PAY_MODE_WITHOUT_PAY ? 0.0 : $durationDays);

        return [
            'id' => $app->id,
            'employee_control_no' => $app->employee_control_no,
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
            'pending_update' => $pendingUpdateMeta['payload'],
            'pending_update_reason' => $pendingUpdateMeta['reason'],
            'pending_update_previous_status' => $pendingUpdateMeta['previous_status'],
            'pending_update_requested_by' => $pendingUpdateMeta['requested_by'],
            'pending_update_requested_at' => $pendingUpdateMeta['requested_at']?->toIso8601String(),
            'has_pending_update_request' => ($pendingUpdateMeta['payload'] ?? null) !== null,
            'latest_update_request_status' => $latestUpdateMeta['status'],
            'latest_update_request_payload' => $latestUpdateMeta['payload'],
            'latest_update_request_reason' => $latestUpdateMeta['reason'],
            'latest_update_request_previous_status' => $latestUpdateMeta['previous_status'],
            'latest_update_requested_by' => $latestUpdateMeta['requested_by'],
            'latest_update_requested_at' => $latestUpdateMeta['requested_at']?->toIso8601String(),
            'latest_update_reviewed_at' => $latestUpdateMeta['reviewed_at']?->toIso8601String(),
            'latest_update_review_remarks' => $latestUpdateMeta['review_remarks'],
            'dateFiled' => $app->created_at ? $app->created_at->toDateString() : '',
            'selected_dates' => $app->resolvedSelectedDates(),
            'selected_date_pay_status' => $selectedDatePayStatus,
            'selected_date_coverage' => $selectedDateCoverage,
            'pay_mode' => $normalizedPayMode,
            'pay_status' => $normalizedPayMode === LeaveApplication::PAY_MODE_WITHOUT_PAY ? 'Without Pay' : 'With Pay',
            'without_pay' => $normalizedPayMode === LeaveApplication::PAY_MODE_WITHOUT_PAY,
            'with_pay' => $normalizedPayMode !== LeaveApplication::PAY_MODE_WITHOUT_PAY,
            'deductible_days' => $deductibleDays,
            'is_monetization' => (bool) $app->is_monetization,
            'equivalent_amount' => $app->equivalent_amount ? (float) $app->equivalent_amount : null,
            'leaveBalance' => $this->getBalanceForApp($app),
        ];
    }

    private function getPendingApprovedUpdateRequestRecord(LeaveApplication $app): ?LeaveApplicationUpdateRequest
    {
        if (!$app->id) {
            return null;
        }

        if ($app->relationLoaded('updateRequests')) {
            $record = $app->updateRequests
                ->filter(fn($item) => $item instanceof LeaveApplicationUpdateRequest)
                ->sortByDesc(fn(LeaveApplicationUpdateRequest $item) => (int) $item->id)
                ->first(function (LeaveApplicationUpdateRequest $item): bool {
                    return strtoupper(trim((string) $item->status)) === LeaveApplicationUpdateRequest::STATUS_PENDING
                        && strtoupper(trim((string) ($item->previous_status ?? ''))) === LeaveApplication::STATUS_APPROVED;
                });

            return $record instanceof LeaveApplicationUpdateRequest ? $record : null;
        }

        $record = LeaveApplicationUpdateRequest::query()
            ->where('leave_application_id', (int) $app->id)
            ->where('status', LeaveApplicationUpdateRequest::STATUS_PENDING)
            ->latest('id')
            ->first();

        if (!$record) {
            return null;
        }

        $previousStatus = strtoupper(trim((string) ($record->previous_status ?? '')));
        return $previousStatus === LeaveApplication::STATUS_APPROVED ? $record : null;
    }

    private function resolvePendingUpdateMeta(LeaveApplication $app): array
    {
        $pendingRequest = $this->getPendingApprovedUpdateRequestRecord($app);
        if ($pendingRequest instanceof LeaveApplicationUpdateRequest) {
            return [
                'payload' => is_array($pendingRequest->requested_payload) ? $pendingRequest->requested_payload : null,
                'reason' => $this->trimNullableString($pendingRequest->requested_reason),
                'previous_status' => strtoupper(trim((string) ($pendingRequest->previous_status ?? ''))),
                'requested_by' => $this->trimNullableString($pendingRequest->requested_by_control_no),
                'requested_at' => $pendingRequest->requested_at,
            ];
        }

        return [
            'payload' => null,
            'reason' => null,
            'previous_status' => null,
            'requested_by' => null,
            'requested_at' => null,
        ];
    }

    private function getLatestApprovedUpdateRequestRecord(LeaveApplication $app): ?LeaveApplicationUpdateRequest
    {
        if (!$app->id) {
            return null;
        }

        if ($app->relationLoaded('updateRequests')) {
            $record = $app->updateRequests
                ->filter(fn($item) => $item instanceof LeaveApplicationUpdateRequest)
                ->sortByDesc(fn(LeaveApplicationUpdateRequest $item) => (int) $item->id)
                ->first(function (LeaveApplicationUpdateRequest $item): bool {
                    return strtoupper(trim((string) ($item->previous_status ?? ''))) === LeaveApplication::STATUS_APPROVED;
                });

            return $record instanceof LeaveApplicationUpdateRequest ? $record : null;
        }

        $record = LeaveApplicationUpdateRequest::query()
            ->where('leave_application_id', (int) $app->id)
            ->latest('id')
            ->first();

        if (!$record) {
            return null;
        }

        $previousStatus = strtoupper(trim((string) ($record->previous_status ?? '')));
        return $previousStatus === LeaveApplication::STATUS_APPROVED ? $record : null;
    }

    private function resolveLatestUpdateMeta(LeaveApplication $app): array
    {
        $latestRequest = $this->getLatestApprovedUpdateRequestRecord($app);
        if ($latestRequest instanceof LeaveApplicationUpdateRequest) {
            return [
                'status' => strtoupper(trim((string) ($latestRequest->status ?? ''))),
                'payload' => is_array($latestRequest->requested_payload) ? $latestRequest->requested_payload : null,
                'reason' => $this->trimNullableString($latestRequest->requested_reason),
                'previous_status' => strtoupper(trim((string) ($latestRequest->previous_status ?? ''))),
                'requested_by' => $this->trimNullableString($latestRequest->requested_by_control_no),
                'requested_at' => $latestRequest->requested_at,
                'reviewed_at' => $latestRequest->reviewed_at,
                'review_remarks' => $this->trimNullableString($latestRequest->review_remarks),
            ];
        }

        return [
            'status' => null,
            'payload' => null,
            'reason' => null,
            'previous_status' => null,
            'requested_by' => null,
            'requested_at' => null,
            'reviewed_at' => null,
            'review_remarks' => null,
        ];
    }

    private function trimNullableString(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $trimmed = trim((string) $value);
        return $trimmed === '' ? null : $trimmed;
    }

    private function getBalanceForApp(LeaveApplication $app): ?float
    {
        if ($app->employee_control_no) {
            $employeeControlNo = trim((string) $app->employee_control_no);
            $candidateEmployeeControlNos = $this->controlNoCandidates($employeeControlNo);
            if ($candidateEmployeeControlNos === []) {
                return null;
            }

            $balance = LeaveBalance::query()
                ->where('leave_type_id', $app->leave_type_id)
                ->whereIn('employee_control_no', $candidateEmployeeControlNos)
                ->first();
            return $balance ? (float) $balance->balance : null;
        }

        if ($app->applicant_admin_id) {
            $adminControlNo = $this->resolveAdminEmployeeControlNo((int) $app->applicant_admin_id);
            if ($adminControlNo === null) {
                return null;
            }

            $candidateEmployeeControlNos = $this->controlNoCandidates($adminControlNo);
            if ($candidateEmployeeControlNos === []) {
                return null;
            }

            $balance = LeaveBalance::query()
                ->where('leave_type_id', $app->leave_type_id)
                ->whereIn('employee_control_no', $candidateEmployeeControlNos)
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
                $q->whereIn('employee_control_no', function ($sq) use ($dept) {
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
            'employee_control_no' => $app->employee_control_no,
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
