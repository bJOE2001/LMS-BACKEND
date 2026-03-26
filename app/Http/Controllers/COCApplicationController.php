<?php

namespace App\Http\Controllers;

use App\Models\COCApplication;
use App\Models\COCApplicationRow;
use App\Models\DepartmentAdmin;
use App\Models\HRAccount;
use App\Models\HrisEmployee;
use App\Models\LeaveBalance;
use App\Models\LeaveType;
use App\Models\Notification;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class COCApplicationController extends Controller
{
    private const MINUTES_PER_WORKDAY = 480;

    public function ermsIndex(Request $request): JsonResponse
    {
        $this->mergeEmployeeControlNoInput($request);

        $validated = $request->validate([
            'employee_control_no' => ['nullable', 'string', 'regex:/^\d+$/'],
        ]);

        $controlNo = $this->resolveValidatedEmployeeControlNo($validated);
        if ($controlNo === '') {
            return response()->json(['message' => 'The employee_control_no query parameter is required.'], 422);
        }

        $employee = HrisEmployee::findByControlNo($controlNo);
        if (!$employee) {
            return response()->json(['message' => 'Employee record not found.'], 404);
        }

        if ($this->isCocRestrictedEmployeeStatus($employee->status)) {
            return response()->json([
                ...$this->employeeControlNoResponse((string) $employee->control_no),
                'applications' => [],
            ]);
        }

        $applications = COCApplication::query()
            ->with(['rows', 'reviewedByAdmin', 'reviewedByHr', 'ctoLeaveType'])
            ->matchingControlNo($controlNo)
            ->orderByDesc('created_at')
            ->get();

        $leaveBalanceDirectory = $this->buildLeaveBalanceDirectory($applications);

        return response()->json([
            ...$this->employeeControlNoResponse((string) $employee->control_no),
            'applications' => $applications
                ->map(fn(COCApplication $app) => $this->formatApplication($app, $leaveBalanceDirectory))
                ->values(),
        ]);
    }

    public function ermsStore(Request $request): JsonResponse
    {
        $this->mergeEmployeeControlNoInput($request);

        $validated = $request->validate([
            'employee_control_no' => ['required', 'string', 'regex:/^\d+$/'],
            'rows' => ['required', 'array', 'min:1', 'max:100'],
            'rows.*.date' => ['required', 'date'],
            'rows.*.nature_of_overtime' => ['required', 'string', 'max:2000'],
            'rows.*.time_from' => ['required', 'string', 'regex:/^\d{2}:\d{2}$/'],
            'rows.*.time_to' => ['required', 'string', 'regex:/^\d{2}:\d{2}$/'],
            'total_no_of_coc_applied_minutes' => ['nullable', 'integer', 'min:1', 'max:144000'],
        ]);

        $employee = HrisEmployee::findByControlNo($this->resolveValidatedEmployeeControlNo($validated));
        if (!$employee) {
            return response()->json(['message' => 'Employee record not found.'], 404);
        }

        if ($this->isCocRestrictedEmployeeStatus($employee->status)) {
            return response()->json([
                'message' => 'COC application is not available for contractual employees.',
            ], 422);
        }

        $runningMinutes = 0;
        $rows = [];
        foreach ($validated['rows'] as $index => $row) {
            $minutes = $this->calculateDurationMinutes((string) $row['time_from'], (string) $row['time_to']);
            if ($minutes === null || $minutes <= 0) {
                return response()->json([
                    'message' => 'Invalid overtime time range detected.',
                    'errors' => ["rows.{$index}.time_to" => ['The overtime end time must be after the start time.']],
                ], 422);
            }

            $runningMinutes += $minutes;
            $rows[] = [
                'line_no' => $index + 1,
                'overtime_date' => $row['date'],
                'nature_of_overtime' => trim((string) $row['nature_of_overtime']),
                'time_from' => (string) $row['time_from'],
                'time_to' => (string) $row['time_to'],
                'minutes' => $minutes,
                'cumulative_minutes' => $runningMinutes,
            ];
        }

        $submittedTotalMinutes = isset($validated['total_no_of_coc_applied_minutes'])
            ? (int) $validated['total_no_of_coc_applied_minutes']
            : null;
        if ($submittedTotalMinutes !== null && $submittedTotalMinutes !== $runningMinutes) {
            return response()->json([
                'message' => 'Total COC minutes do not match the provided overtime rows.',
            ], 422);
        }

        $application = DB::transaction(function () use ($employee, $rows, $runningMinutes): COCApplication {
            $app = COCApplication::create([
                'employee_control_no' => (string) $employee->control_no,
                'employee_name' => trim(implode(' ', array_filter([
                    trim((string) ($employee->firstname ?? '')),
                    trim((string) ($employee->middlename ?? '')),
                    trim((string) ($employee->surname ?? '')),
                ]))) ?: null,
                'status' => COCApplication::STATUS_PENDING,
                'total_minutes' => $runningMinutes,
                'submitted_at' => now(),
            ]);

            foreach ($rows as $row) {
                $app->rows()->create([
                    ...$row,
                    'employee_control_no' => (string) $app->employee_control_no,
                    'employee_name' => $app->employee_name,
                ]);
            }

            return $app;
        });

        $application->load(['rows', 'reviewedByAdmin', 'reviewedByHr', 'ctoLeaveType']);

        $this->notifyDepartmentAdminsOfSubmittedCoc($employee, $application);

        return response()->json([
            'message' => 'COC application submitted successfully.',
            'application' => $this->formatApplication($application),
        ], 201);
    }

    public function adminIndex(Request $request): JsonResponse
    {
        $admin = $request->user();
        if (!$admin instanceof DepartmentAdmin) {
            return response()->json(['message' => 'Only department admins can view COC applications.'], 403);
        }

        $validated = $request->validate([
            'status' => ['nullable', 'string', 'max:50'],
            'employee_control_no' => ['nullable', 'string', 'regex:/^\d+$/'],
        ]);

        $statusFilter = $this->normalizeStatusFilter($validated['status'] ?? null);
        if (($validated['status'] ?? null) !== null && $statusFilter === null) {
            return response()->json(['message' => 'Invalid status filter.'], 422);
        }

        $controlNo = $this->resolveValidatedEmployeeControlNo($validated);

        $applications = $this->departmentScope($admin)
            ->with(['rows', 'reviewedByAdmin', 'reviewedByHr', 'ctoLeaveType'])
            ->when($controlNo !== '', function ($q) use ($controlNo): void {
                $q->matchingControlNo($controlNo);
            })
            ->orderByDesc('created_at')
            ->get();

        $applications = $this->filterByRawStatus($applications, $statusFilter);
        $leaveBalanceDirectory = $this->buildLeaveBalanceDirectory($applications);

        return response()->json([
            'applications' => $applications
                ->map(fn(COCApplication $app) => $this->formatApplication($app, $leaveBalanceDirectory))
                ->values(),
        ]);
    }

    public function adminShow(Request $request, int $id): JsonResponse
    {
        $admin = $request->user();
        if (!$admin instanceof DepartmentAdmin) {
            return response()->json(['message' => 'Only department admins can view COC applications.'], 403);
        }

        $application = $this->departmentScope($admin)
            ->with(['rows', 'reviewedByAdmin', 'reviewedByHr', 'ctoLeaveType'])
            ->where('id', $id)
            ->first();

        if (!$application) {
            return response()->json(['message' => 'COC application not found.'], 404);
        }

        return response()->json([
            'application' => $this->formatApplication($application),
        ]);
    }

    public function adminApprove(Request $request, int $id): JsonResponse
    {
        $admin = $request->user();
        if (!$admin instanceof DepartmentAdmin) {
            return response()->json(['message' => 'Only department admins can approve COC applications.'], 403);
        }

        $validated = $request->validate(['remarks' => ['nullable', 'string', 'max:2000']]);

        try {
            DB::transaction(function () use ($admin, $id, $validated): void {
                $app = $this->departmentScope($admin)->lockForUpdate()->where('id', $id)->first();
                if (!$app) {
                    throw new RuntimeException('NOT_FOUND');
                }
                if ($app->status !== COCApplication::STATUS_PENDING) {
                    throw new RuntimeException('ALREADY_REVIEWED');
                }
                if ($app->admin_reviewed_at !== null) {
                    throw new RuntimeException('ALREADY_FORWARDED_HR');
                }

                $app->update([
                    'reviewed_by_admin_id' => $admin->id,
                    'admin_reviewed_at' => now(),
                    'remarks' => trim((string) ($validated['remarks'] ?? '')) ?: $app->remarks,
                ]);
            });
        } catch (RuntimeException $exception) {
            return $this->handleRuntimeException($exception);
        }

        $app = COCApplication::query()->with(['rows', 'reviewedByAdmin', 'reviewedByHr', 'ctoLeaveType'])->find($id);
        if ($app) {
            $this->notifyHrOfPendingCocReview($app);
        }

        return response()->json([
            'message' => 'COC application approved and forwarded to HR.',
            'application' => $app ? $this->formatApplication($app) : null,
        ]);
    }

    public function adminReject(Request $request, int $id): JsonResponse
    {
        $admin = $request->user();
        if (!$admin instanceof DepartmentAdmin) {
            return response()->json(['message' => 'Only department admins can reject COC applications.'], 403);
        }

        $validated = $request->validate(['remarks' => ['nullable', 'string', 'max:2000']]);

        try {
            DB::transaction(function () use ($admin, $id, $validated): void {
                $app = $this->departmentScope($admin)->lockForUpdate()->where('id', $id)->first();
                if (!$app) {
                    throw new RuntimeException('NOT_FOUND');
                }
                if ($app->status !== COCApplication::STATUS_PENDING) {
                    throw new RuntimeException('ALREADY_REVIEWED');
                }

                $app->update([
                    'status' => COCApplication::STATUS_REJECTED,
                    'reviewed_by_admin_id' => $admin->id,
                    'admin_reviewed_at' => now(),
                    'remarks' => trim((string) ($validated['remarks'] ?? '')) ?: $app->remarks,
                    'reviewed_by_hr_id' => null,
                    'reviewed_at' => null,
                    'cto_leave_type_id' => null,
                    'cto_credited_days' => null,
                    'cto_credited_at' => null,
                ]);
            });
        } catch (RuntimeException $exception) {
            return $this->handleRuntimeException($exception);
        }

        $app = COCApplication::query()->with(['rows', 'reviewedByAdmin', 'reviewedByHr', 'ctoLeaveType'])->find($id);

        return response()->json([
            'message' => 'COC application rejected by department admin.',
            'application' => $app ? $this->formatApplication($app) : null,
        ]);
    }

    public function hrIndex(Request $request): JsonResponse
    {
        $hr = $request->user();
        if (!$hr instanceof HRAccount) {
            return response()->json(['message' => 'Only HR accounts can view COC applications.'], 403);
        }

        $validated = $request->validate([
            'status' => ['nullable', 'string', 'max:50'],
            'employee_control_no' => ['nullable', 'string', 'regex:/^\d+$/'],
        ]);

        $statusFilter = $this->normalizeStatusFilter($validated['status'] ?? null);
        if (($validated['status'] ?? null) !== null && $statusFilter === null) {
            return response()->json(['message' => 'Invalid status filter.'], 422);
        }

        $controlNo = $this->resolveValidatedEmployeeControlNo($validated);

        $applications = COCApplication::query()
            ->with(['rows', 'reviewedByAdmin', 'reviewedByHr', 'ctoLeaveType'])
            ->when($controlNo !== '', function ($q) use ($controlNo): void {
                $q->matchingControlNo($controlNo);
            })
            ->orderByDesc('created_at')
            ->get();

        $applications = $this->filterByRawStatus($applications, $statusFilter);
        $leaveBalanceDirectory = $this->buildLeaveBalanceDirectory($applications);

        return response()->json([
            'applications' => $applications
                ->map(fn(COCApplication $app) => $this->formatApplication($app, $leaveBalanceDirectory))
                ->values(),
        ]);
    }

    public function hrShow(Request $request, int $id): JsonResponse
    {
        $hr = $request->user();
        if (!$hr instanceof HRAccount) {
            return response()->json(['message' => 'Only HR accounts can view COC applications.'], 403);
        }

        $application = COCApplication::query()
            ->with(['rows', 'reviewedByAdmin', 'reviewedByHr', 'ctoLeaveType'])
            ->find($id);

        if (!$application) {
            return response()->json(['message' => 'COC application not found.'], 404);
        }

        return response()->json([
            'application' => $this->formatApplication($application),
        ]);
    }

    public function hrApprove(Request $request, int $id): JsonResponse
    {
        $hr = $request->user();
        if (!$hr instanceof HRAccount) {
            return response()->json(['message' => 'Only HR accounts can approve COC applications.'], 403);
        }

        $validated = $request->validate(['remarks' => ['nullable', 'string', 'max:2000']]);

        try {
            $result = DB::transaction(function () use ($id, $hr, $validated): array {
                $app = COCApplication::query()->lockForUpdate()->find($id);
                if (!$app) throw new RuntimeException('NOT_FOUND');
                if ($app->status !== COCApplication::STATUS_PENDING) throw new RuntimeException('ALREADY_REVIEWED');
                if ($app->admin_reviewed_at === null) throw new RuntimeException('PENDING_ADMIN_REVIEW');

                $employee = HrisEmployee::findByControlNo((string) $app->employee_control_no);
                if (!$employee) throw new RuntimeException('EMPLOYEE_NOT_FOUND');

                $ctoLeaveType = $this->resolveCTOLeaveType();
                if (!$ctoLeaveType) throw new RuntimeException('CTO_MISSING');

                $creditedDays = $this->minutesToLeaveDays((int) $app->total_minutes);
                if ($creditedDays <= 0) throw new RuntimeException('INVALID_CREDIT');

                $balance = LeaveBalance::query()
                    ->where('employee_control_no', (string) $employee->control_no)
                    ->where('leave_type_id', (int) $ctoLeaveType->id)
                    ->lockForUpdate()
                    ->first();

                if ($balance) {
                    $balance->balance = round((float) $balance->balance + $creditedDays, 2);
                    if (!$balance->year) $balance->year = (int) now()->year;
                    $balance->save();
                } else {
                    $balance = LeaveBalance::query()->create([
                        'employee_control_no' => (string) $employee->control_no,
                        'leave_type_id' => (int) $ctoLeaveType->id,
                        'balance' => $creditedDays,
                        'year' => (int) now()->year,
                    ]);
                }

                $app->update([
                    'status' => COCApplication::STATUS_APPROVED,
                    'reviewed_by_hr_id' => $hr->id,
                    'reviewed_at' => now(),
                    'cto_leave_type_id' => (int) $ctoLeaveType->id,
                    'cto_credited_days' => $creditedDays,
                    'cto_credited_at' => now(),
                    'remarks' => trim((string) ($validated['remarks'] ?? '')) ?: $app->remarks,
                ]);

                return ['days' => $creditedDays, 'balance' => (float) $balance->balance, 'leave_type' => $ctoLeaveType];
            });
        } catch (RuntimeException $exception) {
            return $this->handleRuntimeException($exception);
        }

        $app = COCApplication::query()->with(['rows', 'reviewedByAdmin', 'reviewedByHr', 'ctoLeaveType'])->find($id);
        if ($app) {
            $creditedDays = isset($result['days']) ? (float) $result['days'] : null;
            $this->notifyAdminOfHrCocDecision($app, true, $creditedDays);
        }

        return response()->json([
            'message' => 'COC application approved and converted to CTO leave credits.',
            'application' => $app ? $this->formatApplication($app) : null,
            'cto_credit' => [
                'leave_type_id' => (int) $result['leave_type']->id,
                'leave_type_name' => (string) $result['leave_type']->name,
                'credited_days' => (float) $result['days'],
                'expires_on' => now()->addYearNoOverflow()->toDateString(),
                'updated_balance' => (float) $result['balance'],
            ],
        ]);
    }

    public function hrReject(Request $request, int $id): JsonResponse
    {
        $hr = $request->user();
        if (!$hr instanceof HRAccount) {
            return response()->json(['message' => 'Only HR accounts can reject COC applications.'], 403);
        }

        $validated = $request->validate(['remarks' => ['nullable', 'string', 'max:2000']]);

        try {
            DB::transaction(function () use ($id, $hr, $validated): void {
                $app = COCApplication::query()->lockForUpdate()->find($id);
                if (!$app) throw new RuntimeException('NOT_FOUND');
                if ($app->status !== COCApplication::STATUS_PENDING) throw new RuntimeException('ALREADY_REVIEWED');
                if ($app->admin_reviewed_at === null) throw new RuntimeException('PENDING_ADMIN_REVIEW');

                $app->update([
                    'status' => COCApplication::STATUS_REJECTED,
                    'reviewed_by_hr_id' => $hr->id,
                    'reviewed_at' => now(),
                    'remarks' => trim((string) ($validated['remarks'] ?? '')) ?: $app->remarks,
                    'cto_leave_type_id' => null,
                    'cto_credited_days' => null,
                    'cto_credited_at' => null,
                ]);
            });
        } catch (RuntimeException $exception) {
            return $this->handleRuntimeException($exception);
        }

        $app = COCApplication::query()->with(['rows', 'reviewedByAdmin', 'reviewedByHr', 'ctoLeaveType'])->find($id);
        if ($app) {
            $this->notifyAdminOfHrCocDecision($app, false);
        }

        return response()->json([
            'message' => 'COC application rejected.',
            'application' => $app ? $this->formatApplication($app) : null,
        ]);
    }

    private function calculateDurationMinutes(string $timeFrom, string $timeTo): ?int
    {
        $from = $this->parseTimeToMinutes($timeFrom);
        $to = $this->parseTimeToMinutes($timeTo);
        if ($from === null || $to === null) return null;
        $duration = $to - $from;
        if ($duration <= 0) $duration += 24 * 60;
        return ($duration > 0 && $duration <= 24 * 60) ? $duration : null;
    }

    private function parseTimeToMinutes(string $value): ?int
    {
        if (!preg_match('/^(?<h>\d{2}):(?<m>\d{2})$/', trim($value), $matches)) return null;
        $hour = (int) $matches['h'];
        $minute = (int) $matches['m'];
        if ($hour < 0 || $hour > 23 || $minute < 0 || $minute > 59) return null;
        return ($hour * 60) + $minute;
    }

    private function departmentScope(DepartmentAdmin $admin)
    {
        $admin->loadMissing('department');
        $departmentName = trim((string) ($admin->department?->name ?? ''));

        $query = COCApplication::query();
        if ($departmentName === '') return $query->whereRaw('1 = 0');

        $departmentControlNos = HrisEmployee::controlNosByOffice($departmentName);
        if ($departmentControlNos === []) {
            return $query->whereRaw('1 = 0');
        }

        $candidateControlNos = collect($departmentControlNos)
            ->flatMap(fn (string $controlNo): array => $this->controlNoCandidates($controlNo))
            ->unique()
            ->values()
            ->all();

        if ($candidateControlNos === []) {
            return $query->whereRaw('1 = 0');
        }

        return $query->whereIn('employee_control_no', $candidateControlNos);
    }

    private function normalizeStatusFilter(?string $status): ?string
    {
        $normalized = strtoupper(trim((string) ($status ?? '')));
        if ($normalized === '') return null;
        $normalized = str_replace([' ', '-'], '_', $normalized);

        return in_array($normalized, ['PENDING', 'PENDING_ADMIN', 'PENDING_HR', 'APPROVED', 'REJECTED'], true)
            ? $normalized
            : null;
    }

    private function filterByRawStatus($applications, ?string $statusFilter)
    {
        if (!$statusFilter) return $applications;

        return $applications->filter(function (COCApplication $app) use ($statusFilter): bool {
            $raw = $this->deriveRawStatus($app);
            if ($statusFilter === 'PENDING') return in_array($raw, ['PENDING_ADMIN', 'PENDING_HR'], true);
            return $raw === $statusFilter;
        })->values();
    }

    private function deriveRawStatus(COCApplication $app): string
    {
        if ($app->status !== COCApplication::STATUS_PENDING) return $app->status;
        return $app->admin_reviewed_at ? 'PENDING_HR' : 'PENDING_ADMIN';
    }

    private function resolveCTOLeaveType(): ?LeaveType
    {
        return LeaveType::query()->whereRaw('LOWER(LTRIM(RTRIM(name))) = ?', ['cto leave'])->first();
    }

    private function minutesToLeaveDays(int $minutes): float
    {
        return $minutes > 0 ? round($minutes / self::MINUTES_PER_WORKDAY, 2) : 0.0;
    }

    private function minutesToHours(int $minutes): float
    {
        return $minutes > 0 ? round($minutes / 60, 2) : 0.0;
    }

    private function formatHours(float $hours): string
    {
        $display = $hours === (float) ((int) $hours) ? (string) ((int) $hours) : (string) $hours;
        return "{$display} h";
    }

    private function handleRuntimeException(RuntimeException $exception): JsonResponse
    {
        return match ($exception->getMessage()) {
            'NOT_FOUND' => response()->json(['message' => 'COC application not found.'], 404),
            'ALREADY_REVIEWED' => response()->json(['message' => 'This COC application was already reviewed.'], 422),
            'ALREADY_FORWARDED_HR' => response()->json(['message' => 'This COC application is already pending HR review.'], 422),
            'PENDING_ADMIN_REVIEW' => response()->json(['message' => 'This COC application is still pending department admin review.'], 422),
            'EMPLOYEE_NOT_FOUND' => response()->json(['message' => 'Employee record not found for this COC application.'], 422),
            'CTO_MISSING' => response()->json(['message' => 'CTO Leave type is missing. Seed or create CTO Leave first.'], 422),
            'INVALID_CREDIT' => response()->json(['message' => 'Invalid COC credit amount.'], 422),
            default => throw $exception,
        };
    }

    private function mergeEmployeeControlNoInput(Request $request): void
    {
        $employeeControlNo = trim((string) $request->input('employee_control_no', ''));
        if ($employeeControlNo === '') {
            return;
        }

        $request->merge([
            'employee_control_no' => $employeeControlNo,
        ]);
    }

    private function resolveValidatedEmployeeControlNo(array $validated): string
    {
        return trim((string) ($validated['employee_control_no'] ?? ''));
    }

    private function employeeControlNoResponse(?string $controlNo): array
    {
        $normalized = trim((string) ($controlNo ?? ''));
        if ($normalized === '') {
            return [
                'employee_control_no' => null,
            ];
        }

        return [
            'employee_control_no' => $normalized,
        ];
    }

    private function isCocRestrictedEmployeeStatus(mixed $status): bool
    {
        $normalized = strtoupper(trim((string) ($status ?? '')));
        if ($normalized === '') {
            return false;
        }

        return str_contains($normalized, 'CONTRACTUAL')
            || str_contains($normalized, 'HONORARIUM')
            || str_contains($normalized, 'JOB ORDER');
    }

    private function formatApplication(COCApplication $app, array $leaveBalanceDirectory = []): array
    {
        $rows = $app->relationLoaded('rows') ? $app->rows->values() : collect();
        $rowDates = $rows
            ->map(fn(COCApplicationRow $row) => $row->overtime_date?->toDateString())
            ->filter(fn(?string $date) => (string) $date !== '')
            ->unique()
            ->sort()
            ->values()
            ->all();

        $rawStatus = $this->deriveRawStatus($app);
        $status = match ($rawStatus) {
            'PENDING_ADMIN' => 'Pending Admin',
            'PENDING_HR' => 'Pending HR',
            'APPROVED' => 'Approved',
            'REJECTED' => 'Rejected',
            default => $rawStatus,
        };

        $resolvedEmployee = HrisEmployee::findByControlNo((string) ($app->employee_control_no ?? ''));
        $employeeName = trim((string) ($app->employee_name ?? '')) ?: trim(implode(' ', array_filter([
            trim((string) ($resolvedEmployee?->firstname ?? '')),
            trim((string) ($resolvedEmployee?->middlename ?? '')),
            trim((string) ($resolvedEmployee?->surname ?? '')),
        ]))) ?: null;
        $employeePosition = trim((string) ($resolvedEmployee?->designation ?? '')) ?: null;

        $remarks = trim((string) ($app->remarks ?? ''));
        $isCancelled = (bool) preg_match('/^cancelled\b/i', $remarks);
        $durationHours = $this->minutesToHours((int) $app->total_minutes);
        $currentLeaveBalances = $this->getCurrentLeaveBalancesForApp($app, $leaveBalanceDirectory);

        return [
            'id' => $app->id,
            'application_type' => 'COC',
            'employee_control_no' => (string) $app->employee_control_no,
            'employeeName' => $employeeName,
            'employee_name' => $employeeName,
            'office' => $resolvedEmployee?->office,
            'department' => $resolvedEmployee?->office,
            'position' => $employeePosition,
            'designation' => $employeePosition,
            'job_title' => $employeePosition,
            'jobTitle' => $employeePosition,
            'leaveType' => 'COC Application',
            'leave_type_name' => 'COC Application',
            'startDate' => $rowDates[0] ?? null,
            'endDate' => $rowDates !== [] ? $rowDates[count($rowDates) - 1] : null,
            'start_date' => $rowDates[0] ?? null,
            'end_date' => $rowDates !== [] ? $rowDates[count($rowDates) - 1] : null,
            'selected_dates' => $rowDates,
            'days' => $durationHours,
            'total_days' => $durationHours,
            'duration_value' => $durationHours,
            'duration_unit' => 'hour',
            'duration_label' => $this->formatHours($durationHours),
            'status' => $status,
            'rawStatus' => $rawStatus,
            'raw_status' => $rawStatus,
            'remarks' => $app->remarks,
            'cancelled' => $isCancelled,
            'cancellation_reason' => $isCancelled ? $remarks : null,
            'adminActionBy' => $app->reviewedByAdmin?->full_name,
            'admin_action_by' => $app->reviewedByAdmin?->full_name,
            'adminActionAt' => $app->admin_reviewed_at?->toIso8601String(),
            'admin_action_at' => $app->admin_reviewed_at?->toIso8601String(),
            'hrActionBy' => $app->reviewedByHr?->full_name,
            'hr_action_by' => $app->reviewedByHr?->full_name,
            'hrActionAt' => $app->reviewed_at?->toIso8601String(),
            'hr_action_at' => $app->reviewed_at?->toIso8601String(),
            'processedBy' => $rawStatus === 'PENDING_HR'
                ? $app->reviewedByAdmin?->full_name
                : ($app->reviewedByHr?->full_name ?? $app->reviewedByAdmin?->full_name),
            'processed_by' => $rawStatus === 'PENDING_HR'
                ? $app->reviewedByAdmin?->full_name
                : ($app->reviewedByHr?->full_name ?? $app->reviewedByAdmin?->full_name),
            'reviewedAt' => $rawStatus === 'PENDING_HR'
                ? $app->admin_reviewed_at?->toIso8601String()
                : ($app->reviewed_at?->toIso8601String() ?? $app->admin_reviewed_at?->toIso8601String()),
            'reviewed_at' => $rawStatus === 'PENDING_HR'
                ? $app->admin_reviewed_at?->toIso8601String()
                : ($app->reviewed_at?->toIso8601String() ?? $app->admin_reviewed_at?->toIso8601String()),
            'approver_name' => $app->reviewedByHr?->full_name ?? $app->reviewedByAdmin?->full_name,
            'reviewed_by_admin_id' => $app->reviewed_by_admin_id,
            'admin_reviewed_at' => $app->admin_reviewed_at?->toIso8601String(),
            'reviewed_by_hr_id' => $app->reviewed_by_hr_id,
            'reviewed_at_hr' => $app->reviewed_at?->toIso8601String(),
            'total_no_of_coc_applied_minutes' => (int) $app->total_minutes,
            'cto_leave_type_id' => $app->cto_leave_type_id,
            'cto_leave_type_name' => $app->ctoLeaveType?->name,
            'cto_credited_days' => $app->cto_credited_days !== null ? (float) $app->cto_credited_days : null,
            'cto_credited_at' => $app->cto_credited_at?->toIso8601String(),
            'cto_expires_on' => $app->cto_credited_at?->copy()->addYearNoOverflow()->toDateString(),
            'dateFiled' => $app->created_at?->toDateString(),
            'date_filed' => $app->created_at?->toDateString(),
            'filedAt' => $app->created_at?->toIso8601String(),
            'filed_at' => $app->created_at?->toIso8601String(),
            'createdAt' => $app->created_at?->toIso8601String(),
            'created_at' => $app->created_at?->toIso8601String(),
            'submittedAt' => $app->submitted_at?->toIso8601String(),
            'submitted_at' => $app->submitted_at?->toIso8601String(),
            'leaveBalances' => $currentLeaveBalances,
            'employee_leave_balances' => $currentLeaveBalances,
            'rows' => $rows->map(fn(COCApplicationRow $row) => [
                'employee_control_no' => (string) $row->employee_control_no,
                'employee_name' => $row->employee_name,
                'line_no' => (int) $row->line_no,
                'date' => $row->overtime_date?->toDateString(),
                'nature_of_overtime' => $row->nature_of_overtime,
                'time_from' => $row->time_from,
                'time_to' => $row->time_to,
                'no_of_hours_and_minutes' => (int) $row->minutes,
                'total_no_of_hours_and_minutes' => (int) $row->cumulative_minutes,
            ])->values(),
        ];
    }

    private function buildLeaveBalanceDirectory(Collection $applications): array
    {
        $employeeControlNos = $applications
            ->map(fn(COCApplication $application) => trim((string) $application->employee_control_no))
            ->filter(fn(string $controlNo) => $controlNo !== '')
            ->unique()
            ->values();

        if ($employeeControlNos->isEmpty()) {
            return [];
        }

        return LeaveBalance::query()
            ->with('leaveType:id,name')
            ->whereIn('employee_control_no', $employeeControlNos->all())
            ->get()
            ->groupBy(fn(LeaveBalance $balance) => $this->normalizeControlNo($balance->employee_control_no))
            ->map(fn(Collection $balances) => $this->formatLeaveBalanceSnapshot($balances))
            ->all();
    }

    private function getCurrentLeaveBalancesForApp(COCApplication $app, array $leaveBalanceDirectory = []): array
    {
        $employeeKey = $this->normalizeControlNo($app->employee_control_no);
        if ($employeeKey === '') {
            return [];
        }

        if (array_key_exists($employeeKey, $leaveBalanceDirectory)) {
            return $leaveBalanceDirectory[$employeeKey];
        }

        return $this->formatLeaveBalanceSnapshot(
            LeaveBalance::query()
                ->with('leaveType:id,name')
                ->where('employee_control_no', trim((string) $app->employee_control_no))
                ->get()
        );
    }

    private function formatLeaveBalanceSnapshot(Collection $balances): array
    {
        return $balances
            ->sortByDesc(fn(LeaveBalance $balance) => $balance->updated_at?->timestamp ?? 0)
            ->unique(fn(LeaveBalance $balance) => (int) $balance->leave_type_id)
            ->sortBy(fn(LeaveBalance $balance) => strtolower(trim((string) ($balance->leaveType?->name ?? ''))))
            ->values()
            ->map(fn(LeaveBalance $balance) => [
                'leave_type_id' => (int) $balance->leave_type_id,
                'leave_type_name' => $balance->leaveType?->name ?? 'Unknown',
                'balance' => (float) $balance->balance,
                'year' => $balance->year !== null ? (int) $balance->year : null,
                'updated_at' => $balance->updated_at?->toIso8601String(),
            ])
            ->all();
    }

    private function normalizeControlNo(mixed $value): string
    {
        return trim((string) ($value ?? ''));
    }

    /**
     * @return array<int, string>
     */
    private function controlNoCandidates(string $controlNo): array
    {
        $controlNo = trim($controlNo);
        if ($controlNo === '') {
            return [];
        }

        $normalized = ltrim($controlNo, '0');
        if ($normalized === '') {
            $normalized = '0';
        }

        return array_values(array_unique(array_filter([
            $controlNo,
            $normalized,
        ], static fn (string $value): bool => $value !== '')));
    }

    private function notifyDepartmentAdminsOfSubmittedCoc(object $employee, COCApplication $application): void
    {
        $officeName = trim((string) ($employee->office ?? ''));
        if ($officeName === '') {
            return;
        }

        $admins = DepartmentAdmin::query()
            ->whereHas('department', fn ($query) => $query->where('name', $officeName))
            ->get();

        if ($admins->isEmpty()) {
            return;
        }

        $employeeName = $this->resolveEmployeeDisplayName($employee, $application);
        $durationLabel = $this->formatHours($this->minutesToHours((int) ($application->total_minutes ?? 0)));

        foreach ($admins as $admin) {
            Notification::send(
                $admin,
                Notification::TYPE_COC_REQUEST,
                'New COC Application',
                "{$employeeName} submitted a COC application ({$durationLabel}).",
                null,
                (int) $application->id
            );
        }
    }

    private function notifyHrOfPendingCocReview(COCApplication $application): void
    {
        $hrAccounts = HRAccount::query()->get();
        if ($hrAccounts->isEmpty()) {
            return;
        }

        $employee = HrisEmployee::findByControlNo((string) ($application->employee_control_no ?? ''));
        $employeeName = $this->resolveEmployeeDisplayName($employee, $application);
        $durationLabel = $this->formatHours($this->minutesToHours((int) ($application->total_minutes ?? 0)));

        foreach ($hrAccounts as $hrAccount) {
            Notification::send(
                $hrAccount,
                Notification::TYPE_COC_PENDING,
                'COC Application Pending HR Review',
                "{$employeeName}'s COC application ({$durationLabel}) is pending HR review.",
                null,
                (int) $application->id
            );
        }
    }

    private function notifyAdminOfHrCocDecision(COCApplication $application, bool $approved, ?float $creditedDays = null): void
    {
        $admin = $application->reviewedByAdmin;
        if (!$admin instanceof DepartmentAdmin) {
            return;
        }

        $employee = HrisEmployee::findByControlNo((string) ($application->employee_control_no ?? ''));
        $employeeName = $this->resolveEmployeeDisplayName($employee, $application);

        $type = $approved ? Notification::TYPE_COC_APPROVED : Notification::TYPE_COC_REJECTED;
        $title = $approved ? 'COC Application Approved' : 'COC Application Rejected';
        $message = $approved
            ? "COC application for {$employeeName} was approved by HR."
            : "COC application for {$employeeName} was rejected by HR.";

        if ($approved && $creditedDays !== null) {
            $message .= ' CTO credited: ' . $this->formatDays($creditedDays) . '.';
        }

        Notification::send(
            $admin,
            $type,
            $title,
            $message,
            null,
            (int) $application->id
        );
    }

    private function resolveEmployeeDisplayName(?object $employee, COCApplication $application): string
    {
        $employeeName = trim((string) ($application->employee_name ?? ''));
        if ($employeeName !== '') {
            return $employeeName;
        }

        $resolvedName = trim(implode(' ', array_filter([
            trim((string) ($employee?->firstname ?? '')),
            trim((string) ($employee?->middlename ?? '')),
            trim((string) ($employee?->surname ?? '')),
        ])));

        return $resolvedName !== '' ? $resolvedName : 'Employee ' . trim((string) ($application->employee_control_no ?? ''));
    }

    private function formatDays(float $days): string
    {
        $display = $days === (float) ((int) $days) ? (string) ((int) $days) : rtrim(rtrim(number_format($days, 2, '.', ''), '0'), '.');
        return "{$display} day" . ((float) $days === 1.0 ? '' : 's');
    }
}
