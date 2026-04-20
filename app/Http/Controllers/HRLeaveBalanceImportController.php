<?php

namespace App\Http\Controllers;

use App\Models\HRAccount;
use App\Models\HrisEmployee;
use App\Models\LeaveBalance;
use App\Models\LeaveBalanceAccrualHistory;
use App\Models\LeaveType;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * HR-only: manage employee leave balances.
 * Writes stay in LMS_DB; employee validation/lookup reads HRIS.
 */
class HRLeaveBalanceImportController extends Controller
{
    private const MANUAL_CREDIT_PRECISION = 3;

    public function availableTypes(Request $request): JsonResponse
    {
        $user = $request->user();
        if (!$user instanceof HRAccount) {
            return response()->json(['message' => 'Only HR accounts can view available leave balance types.'], 403);
        }

        $validated = $request->validate([
            'employee_control_no' => ['required', 'string', 'max:50', 'regex:/^\d+$/'],
        ]);

        $employeeControlNo = trim((string) ($validated['employee_control_no'] ?? ''));
        $employee = HrisEmployee::findByControlNo($employeeControlNo);
        if (!$employee) {
            return response()->json(['message' => 'Employee not found.'], 422);
        }

        [$allCreditLeaveTypes, $allowedCreditLeaveTypes] = $this->resolveManualCreditLeaveTypeSetsForEmployee($employee);
        if ($allCreditLeaveTypes->isEmpty()) {
            return response()->json([
                'message' => 'No credit-based leave types are configured.',
            ], 422);
        }

        return response()->json([
            'employee_control_no' => (string) ($employee->control_no ?? $employeeControlNo),
            'employee_name' => $this->formatEmployeeNameForStorage($employee),
            'employment_status' => LeaveType::formatEmploymentStatusLabel($employee->status ?? null)
                ?? trim((string) ($employee->status ?? '')),
            'employment_status_key' => LeaveType::normalizeEmploymentStatusKey($employee->status ?? null),
            'leave_types' => $allowedCreditLeaveTypes
                ->map(fn (LeaveType $leaveType): array => $this->formatManualCreditLeaveTypePayload($leaveType))
                ->values(),
        ]);
    }

    /**
     * HR-only: manually add leave credits for an employee (one-time use per employee).
     */
    public function store(Request $request): JsonResponse
    {
        $user = $request->user();
        if (!$user instanceof HRAccount) {
            return response()->json(['message' => 'Only HR accounts can add leave balances.'], 403);
        }

        $validated = $request->validate([
            'employee_control_no' => ['required', 'string', 'max:50', 'regex:/^\d+$/'],
            'leave_type_id' => ['nullable', 'integer', 'exists:tblLeaveTypes,id'],
            'balance' => ['nullable', 'numeric', 'decimal:0,3', 'min:0'],
            'balances' => ['required', 'array', 'min:1'],
            'balances.*.leave_type_id' => ['required_with:balances', 'integer', 'exists:tblLeaveTypes,id'],
            'balances.*.balance' => ['required_with:balances', 'numeric', 'decimal:0,3', 'min:0'],
        ]);

        $employeeControlNo = trim((string) $validated['employee_control_no']);
        $manualCreditSource = $this->resolveManualCreditSource($user);
        $submittedBalances = is_array($validated['balances'] ?? null) ? $validated['balances'] : [];
        if ($submittedBalances === []) {
            return response()->json([
                'message' => 'All leave type balance fields are required.',
            ], 422);
        }

        $employee = HrisEmployee::findByControlNo($employeeControlNo);
        if (!$employee) {
            return response()->json(['message' => 'Employee not found.'], 422);
        }

        [$allCreditLeaveTypes, $creditLeaveTypes] = $this->resolveManualCreditLeaveTypeSetsForEmployee($employee);
        if ($creditLeaveTypes->isEmpty()) {
            return response()->json([
                'message' => 'No credit-based leave types are available for this employee.',
            ], 422);
        }

        $allCreditLeaveTypesById = $allCreditLeaveTypes->keyBy('id');
        $creditLeaveTypesById = $creditLeaveTypes->keyBy('id');
        $submittedTypeIds = [];
        $positiveEntryCount = 0;
        foreach ($submittedBalances as $entry) {
            if (!is_array($entry)) {
                continue;
            }

            $leaveTypeId = (int) ($entry['leave_type_id'] ?? 0);
            $balanceValue = $this->normalizeManualCreditValue($entry['balance'] ?? 0);
            if ($leaveTypeId <= 0) {
                continue;
            }

            $allCreditLeaveType = $allCreditLeaveTypesById->get($leaveTypeId);
            if (!$allCreditLeaveType instanceof LeaveType) {
                return response()->json([
                    'message' => 'Only credit-based leave types can have balances.',
                ], 422);
            }

            if ($this->isManualCreditExcludedLeaveType($allCreditLeaveType)) {
                if ($balanceValue > 0) {
                    return response()->json([
                        'message' => $this->manualCreditExcludedMessage($allCreditLeaveType),
                    ], 422);
                }

                continue;
            }

            $leaveType = $creditLeaveTypesById->get($leaveTypeId);
            if (!$leaveType instanceof LeaveType) {
                if ($balanceValue > 0) {
                    return response()->json([
                        'message' => $this->manualCreditStatusRestrictedMessage($allCreditLeaveType, $employee),
                    ], 422);
                }

                continue;
            }

            if (array_key_exists($leaveTypeId, $submittedTypeIds)) {
                return response()->json([
                    'message' => 'Duplicate leave type entries are not allowed.',
                ], 422);
            }

            $maxDays = $leaveType->max_days !== null ? (float) $leaveType->max_days : null;
            if ($maxDays !== null && $balanceValue > $maxDays) {
                return response()->json([
                    'message' => "{$leaveType->name} cannot exceed {$maxDays} days.",
                ], 422);
            }

            if ($balanceValue > 0) {
                $positiveEntryCount++;
            }

            $submittedTypeIds[$leaveTypeId] = true;
        }

        $requiredTypeIds = $creditLeaveTypesById->keys()->map(fn($id) => (int) $id)->all();
        $missingTypeIds = array_values(array_diff($requiredTypeIds, array_keys($submittedTypeIds)));
        if ($missingTypeIds !== []) {
            $missingNames = $creditLeaveTypesById
                ->only($missingTypeIds)
                ->pluck('name')
                ->filter()
                ->values()
                ->all();
            $missingLabel = implode(', ', array_slice($missingNames, 0, 4));
            $extraCount = count($missingNames) - min(count($missingNames), 4);
            if ($extraCount > 0) {
                $missingLabel .= " and {$extraCount} more";
            }

            return response()->json([
                'message' => $missingLabel !== ''
                    ? "All leave type balances are required. Missing: {$missingLabel}."
                    : 'All leave type balances are required.',
            ], 422);
        }

        if ($positiveEntryCount <= 0) {
            return response()->json([
                'message' => 'At least one leave type must be greater than zero.',
            ], 422);
        }

        $entries = $this->resolveManualCreditEntries($validated);
        if ($entries === []) {
            return response()->json([
                'message' => 'At least one leave type must be greater than zero.',
            ], 422);
        }

        $leaveTypesById = LeaveType::query()
            ->whereIn('id', array_keys($entries))
            ->get()
            ->keyBy('id');

        foreach ($entries as $leaveTypeId => $_creditsToAdd) {
            $leaveType = $leaveTypesById->get((int) $leaveTypeId);
            if (!$leaveType instanceof LeaveType) {
                return response()->json(['message' => 'Leave type not found.'], 422);
            }
            if (!(bool) $leaveType->is_credit_based) {
                return response()->json(['message' => 'Only credit-based leave types can have balances.'], 422);
            }
            if ($this->isManualCreditExcludedLeaveType($leaveType)) {
                return response()->json([
                    'message' => $this->manualCreditExcludedMessage($leaveType),
                ], 422);
            }
            if (
                $this->employeeHasResolvedEmploymentStatus($employee)
                && !$leaveType->allowsEmploymentStatus($employee->status ?? null)
            ) {
                return response()->json([
                    'message' => $this->manualCreditStatusRestrictedMessage($leaveType, $employee),
                ], 422);
            }
        }

        $year = (int) now()->format('Y');
        $storeResult = DB::transaction(function () use (
            $employee,
            $entries,
            $leaveTypesById,
            $year,
            $manualCreditSource
        ): array {
            $recordedAt = now();
            $canonicalControlNo = trim((string) ($employee->control_no ?? ''));
            if ($canonicalControlNo === '') {
                return [
                    'success' => false,
                    'message' => 'Employee record not found.',
                ];
            }

            if ($this->hasManualCreditGrantUsage($canonicalControlNo)) {
                return [
                    'success' => false,
                    'message' => 'Add Leave Credits can only be used once per employee.',
                ];
            }

            $savedBalances = [];
            foreach ($entries as $leaveTypeId => $creditsToAdd) {
                $leaveType = $leaveTypesById->get((int) $leaveTypeId);
                if (!$leaveType instanceof LeaveType) {
                    continue;
                }
                $resolvedEmployeeName = $this->formatEmployeeNameForStorage($employee);
                $resolvedLeaveTypeName = trim((string) ($leaveType->name ?? ''));

                $existing = LeaveBalance::query()
                    ->where('employee_control_no', $canonicalControlNo)
                    ->where('leave_type_id', $leaveType->id)
                    ->lockForUpdate()
                    ->first();

                $previousBalance = $this->normalizeManualCreditValue($existing?->balance ?? 0);
                $newBalance = $this->normalizeManualCreditValue($previousBalance + $creditsToAdd);

                if ($existing) {
                    $existing->balance = $newBalance;
                    $existing->year = $year;
                    if ($resolvedEmployeeName !== '' && trim((string) ($existing->employee_name ?? '')) === '') {
                        $existing->employee_name = $resolvedEmployeeName;
                    }
                    if ($resolvedLeaveTypeName !== '') {
                        $existing->leave_type_name = $resolvedLeaveTypeName;
                    }
                    $existing->save();
                    $leaveBalance = $existing->fresh();
                } else {
                    $leaveBalance = LeaveBalance::query()->create([
                        'employee_control_no' => $canonicalControlNo,
                        'employee_name' => $resolvedEmployeeName !== '' ? $resolvedEmployeeName : null,
                        'leave_type_id' => $leaveType->id,
                        'leave_type_name' => $resolvedLeaveTypeName !== '' ? $resolvedLeaveTypeName : null,
                        'balance' => $newBalance,
                        'year' => $year,
                    ]);
                }

                $this->recordManualCreditLedgerEntry(
                    $leaveBalance,
                    $creditsToAdd,
                    $recordedAt,
                    $manualCreditSource,
                    $resolvedEmployeeName,
                    $resolvedLeaveTypeName
                );
                $savedBalances[] = [
                    'id' => (int) $leaveBalance->id,
                    'employee_control_no' => (string) $leaveBalance->employee_control_no,
                    'leave_type_id' => (int) $leaveBalance->leave_type_id,
                    'leave_type_name' => $leaveType->name,
                    'added_credits' => (float) $creditsToAdd,
                    'balance' => (float) $leaveBalance->balance,
                    'year' => (int) $leaveBalance->year,
                    'updated_at' => $leaveBalance->updated_at?->toIso8601String(),
                ];
            }

            return [
                'success' => true,
                'saved_balances' => $savedBalances,
            ];
        });

        if (!(bool) ($storeResult['success'] ?? false)) {
            return response()->json([
                'message' => (string) ($storeResult['message'] ?? 'Unable to add leave credits.'),
            ], 422);
        }

        $savedBalances = $storeResult['saved_balances'] ?? [];

        return response()->json([
            'message' => 'Leave credits added successfully.',
            'employee_control_no' => (string) $employee->control_no,
            'employee_name' => trim("{$employee->firstname} {$employee->surname}"),
            'saved_count' => is_countable($savedBalances) ? count($savedBalances) : 0,
            'leave_balances' => $savedBalances,
        ]);
    }

    private function resolveManualCreditEntries(array $validated): array
    {
        $entries = [];

        if (is_array($validated['balances'] ?? null)) {
            foreach ($validated['balances'] as $entry) {
                if (!is_array($entry)) {
                    continue;
                }

                $leaveTypeId = (int) ($entry['leave_type_id'] ?? 0);
                $creditsToAdd = $this->normalizeManualCreditValue($entry['balance'] ?? 0);
                if ($leaveTypeId <= 0 || $creditsToAdd <= 0) {
                    continue;
                }

                $entries[$leaveTypeId] = $this->normalizeManualCreditValue(
                    ((float) ($entries[$leaveTypeId] ?? 0)) + $creditsToAdd
                );
            }

            return $entries;
        }

        $singleLeaveTypeId = (int) ($validated['leave_type_id'] ?? 0);
        $singleCreditsToAdd = $this->normalizeManualCreditValue($validated['balance'] ?? 0);
        if ($singleLeaveTypeId > 0 && $singleCreditsToAdd > 0) {
            $entries[$singleLeaveTypeId] = $singleCreditsToAdd;
        }

        return $entries;
    }

    private function resolveManualCreditLeaveTypeSetsForEmployee(object $employee): array
    {
        $allCreditLeaveTypes = LeaveType::query()
            ->withoutLegacySpecialPrivilegeAliases()
            ->where('is_credit_based', true)
            ->orderBy('name')
            ->get(['id', 'name', 'max_days', 'allowed_status']);

        $allowedCreditLeaveTypes = $allCreditLeaveTypes
            ->reject(fn (LeaveType $leaveType) => $this->isManualCreditExcludedLeaveType($leaveType))
            ->filter(fn (LeaveType $leaveType): bool => !$this->employeeHasResolvedEmploymentStatus($employee)
                || $leaveType->allowsEmploymentStatus($employee->status ?? null))
            ->values();

        return [$allCreditLeaveTypes, $allowedCreditLeaveTypes];
    }

    private function employeeHasResolvedEmploymentStatus(object $employee): bool
    {
        return LeaveType::normalizeEmploymentStatusKey($employee->status ?? null) !== null;
    }

    private function manualCreditStatusRestrictedMessage(LeaveType $leaveType, object $employee): string
    {
        $allowedStatusLabels = $leaveType->allowedStatusLabels();
        if ($allowedStatusLabels !== []) {
            return sprintf(
                '%s is only available for %s.',
                $leaveType->name,
                implode(', ', $allowedStatusLabels)
            );
        }

        $statusLabel = LeaveType::formatEmploymentStatusLabel($employee->status ?? null)
            ?? trim((string) ($employee->status ?? ''));

        return sprintf(
            '%s is not available for %s employees.',
            $leaveType->name,
            $statusLabel !== '' ? $statusLabel : 'selected'
        );
    }

    private function formatManualCreditLeaveTypePayload(LeaveType $leaveType): array
    {
        return [
            'id' => (int) $leaveType->id,
            'name' => trim((string) ($leaveType->name ?? '')),
            'max_days' => $leaveType->max_days !== null ? (int) $leaveType->max_days : null,
            'allowed_status' => $leaveType->normalizedAllowedStatuses(),
            'allowed_status_labels' => $leaveType->allowedStatusLabels(),
        ];
    }

    private function isManualCreditExcludedLeaveType(LeaveType $leaveType): bool
    {
        return strcasecmp(trim((string) ($leaveType->name ?? '')), 'CTO Leave') === 0;
    }

    private function manualCreditExcludedMessage(LeaveType $leaveType): string
    {
        if ($this->isManualCreditExcludedLeaveType($leaveType)) {
            return 'CTO Leave cannot be added manually. Use approved COC conversions instead.';
        }

        return 'This leave type cannot be added manually.';
    }

    private function hasManualCreditGrantUsage(string $employeeControlNo): bool
    {
        $candidates = $this->buildControlNoCandidates($employeeControlNo);
        if ($candidates === []) {
            return false;
        }

        return LeaveBalanceAccrualHistory::query()
            ->join('tblLeaveBalances as lb', 'lb.id', '=', 'tblLeaveBalanceCreditHistories.leave_balance_id')
            ->whereIn('lb.employee_control_no', $candidates)
            ->where(function ($query): void {
                $query->whereRaw(
                    "UPPER(LTRIM(RTRIM(COALESCE(tblLeaveBalanceCreditHistories.source, '')))) = ?",
                    ['HR_ADD']
                )->orWhereRaw(
                    "UPPER(LTRIM(RTRIM(COALESCE(tblLeaveBalanceCreditHistories.source, '')))) LIKE ?",
                    ['HR_ADD:%']
                );
            })
            ->exists();
    }

    private function buildControlNoCandidates(?string $controlNo): array
    {
        $rawControlNo = trim((string) $controlNo);
        if ($rawControlNo === '') {
            return [];
        }

        $normalizedControlNo = ltrim($rawControlNo, '0');
        if ($normalizedControlNo === '') {
            $normalizedControlNo = '0';
        }

        return array_values(array_unique(array_filter([
            $rawControlNo,
            $normalizedControlNo,
        ], static fn(string $value): bool => trim($value) !== '')));
    }

    private function recordManualCreditLedgerEntry(
        LeaveBalance $leaveBalance,
        float $creditsToAdd,
        \Illuminate\Support\Carbon $recordedAt,
        string $manualSource,
        string $employeeName = '',
        string $leaveTypeName = ''
    ): void {
        $normalizedCreditsToAdd = $this->normalizeManualCreditValue($creditsToAdd);
        if ($normalizedCreditsToAdd <= 0) {
            return;
        }

        $accrualDate = $recordedAt->toDateString();
        $existingEntry = LeaveBalanceAccrualHistory::query()
            ->where('leave_balance_id', (int) $leaveBalance->id)
            ->where('accrual_date', $accrualDate)
            ->where('source', $manualSource)
            ->lockForUpdate()
            ->first();

        if ($existingEntry) {
            $existingEntry->credits_added = $this->normalizeManualCreditValue(
                (float) $existingEntry->credits_added + $normalizedCreditsToAdd
            );
            $existingEntry->source = $manualSource;
            $existingEntry->employee_control_no = trim((string) ($leaveBalance->employee_control_no ?? '')) ?: null;
            if ($employeeName !== '') {
                $existingEntry->employee_name = $employeeName;
            }
            if ($leaveTypeName !== '') {
                $existingEntry->leave_type_name = $leaveTypeName;
            }
            $existingEntry->save();
            return;
        }

        LeaveBalanceAccrualHistory::query()->create([
            'leave_balance_id' => (int) $leaveBalance->id,
            'employee_control_no' => trim((string) ($leaveBalance->employee_control_no ?? '')) ?: null,
            'employee_name' => $employeeName !== '' ? $employeeName : null,
            'leave_type_name' => $leaveTypeName !== '' ? $leaveTypeName : null,
            'credits_added' => $normalizedCreditsToAdd,
            'accrual_date' => $accrualDate,
            'source' => $manualSource,
        ]);
    }

    private function normalizeManualCreditValue(mixed $value): float
    {
        return round((float) $value, self::MANUAL_CREDIT_PRECISION);
    }

    private function formatEmployeeNameForStorage(object $employee): string
    {
        $surname = trim((string) ($employee->surname ?? ''));
        $firstname = trim((string) ($employee->firstname ?? ''));
        $middlename = trim((string) ($employee->middlename ?? ''));

        $name = '';
        if ($surname !== '') {
            $name .= $surname;
        }

        if ($firstname !== '') {
            $name .= $name !== '' ? ', ' . $firstname : $firstname;
        }

        if ($middlename !== '') {
            $name .= ($name !== '' ? ' ' : '') . $middlename;
        }

        return trim($name);
    }

    private function resolveManualCreditSource(HRAccount $user): string
    {
        $username = preg_replace('/\s+/', ' ', trim((string) ($user->username ?? '')));
        if (!is_string($username) || $username === '') {
            return 'HR_ADD';
        }

        $source = 'HR_ADD:' . $username;
        return substr($source, 0, 32);
    }

}
