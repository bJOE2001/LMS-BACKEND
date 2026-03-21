<?php

namespace App\Http\Controllers;

use App\Models\Employee;
use App\Models\HRAccount;
use App\Models\LeaveBalance;
use App\Models\LeaveBalanceAccrualHistory;
use App\Models\LeaveType;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * HR-only: manage employee leave balances.
 * LOCAL LMS_DB only. No connection to pmis2003 or BIOASD.
 */
class HRLeaveBalanceImportController extends Controller
{
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
            'employee_control_no' => ['required', 'string', 'max:50', 'regex:/^\d+$/', 'exists:tblEmployees,control_no'],
            'leave_type_id' => ['nullable', 'integer', 'exists:tblLeaveTypes,id'],
            'balance' => ['nullable', 'numeric', 'min:0'],
            'balances' => ['required', 'array', 'min:1'],
            'balances.*.leave_type_id' => ['required_with:balances', 'integer', 'exists:tblLeaveTypes,id'],
            'balances.*.balance' => ['required_with:balances', 'numeric', 'min:0'],
        ]);

        $employeeControlNo = trim((string) $validated['employee_control_no']);
        $manualCreditSource = $this->resolveManualCreditSource($user);
        $submittedBalances = is_array($validated['balances'] ?? null) ? $validated['balances'] : [];
        if ($submittedBalances === []) {
            return response()->json([
                'message' => 'All leave type balance fields are required.',
            ], 422);
        }

        $allCreditLeaveTypes = LeaveType::query()
            ->where('is_credit_based', true)
            ->orderBy('name')
            ->get(['id', 'name', 'max_days']);
        $creditLeaveTypes = $allCreditLeaveTypes
            ->reject(fn (LeaveType $leaveType) => $this->isManualCreditExcludedLeaveType($leaveType))
            ->values();
        if ($creditLeaveTypes->isEmpty()) {
            return response()->json([
                'message' => 'No credit-based leave types are configured.',
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
            $balanceValue = round((float) ($entry['balance'] ?? 0), 2);
            if ($leaveTypeId <= 0) {
                continue;
            }

            if (array_key_exists($leaveTypeId, $submittedTypeIds)) {
                return response()->json([
                    'message' => 'Duplicate leave type entries are not allowed.',
                ], 422);
            }

            $leaveType = $creditLeaveTypesById->get($leaveTypeId);
            if (!$leaveType instanceof LeaveType) {
                $blockedLeaveType = $allCreditLeaveTypesById->get($leaveTypeId);
                if ($blockedLeaveType instanceof LeaveType && $this->isManualCreditExcludedLeaveType($blockedLeaveType)) {
                    return response()->json([
                        'message' => $this->manualCreditExcludedMessage($blockedLeaveType),
                    ], 422);
                }

                return response()->json([
                    'message' => 'Only credit-based leave types can have balances.',
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

        $employee = Employee::findByControlNo($employeeControlNo);
        if (!$employee) {
            return response()->json(['message' => 'Employee not found.'], 422);
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
            $lockedEmployee = Employee::query()
                ->matchingControlNo($employee->control_no)
                ->lockForUpdate()
                ->first();
            $canonicalControlNo = trim((string) ($lockedEmployee?->control_no ?? $employee->control_no));
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
                $resolvedEmployeeName = $this->formatEmployeeNameForStorage($lockedEmployee ?? $employee);
                $resolvedLeaveTypeName = trim((string) ($leaveType->name ?? ''));

                $existing = LeaveBalance::query()
                    ->where('employee_control_no', $canonicalControlNo)
                    ->where('leave_type_id', $leaveType->id)
                    ->lockForUpdate()
                    ->first();

                $previousBalance = round((float) ($existing?->balance ?? 0), 2);
                $newBalance = round($previousBalance + $creditsToAdd, 2);

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
                $creditsToAdd = round((float) ($entry['balance'] ?? 0), 2);
                if ($leaveTypeId <= 0 || $creditsToAdd <= 0) {
                    continue;
                }

                $entries[$leaveTypeId] = round(((float) ($entries[$leaveTypeId] ?? 0)) + $creditsToAdd, 2);
            }

            return $entries;
        }

        $singleLeaveTypeId = (int) ($validated['leave_type_id'] ?? 0);
        $singleCreditsToAdd = round((float) ($validated['balance'] ?? 0), 2);
        if ($singleLeaveTypeId > 0 && $singleCreditsToAdd > 0) {
            $entries[$singleLeaveTypeId] = $singleCreditsToAdd;
        }

        return $entries;
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
        $normalizedCreditsToAdd = round((float) $creditsToAdd, 2);
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
                $existingEntry->credits_added = round(
                    (float) $existingEntry->credits_added + $normalizedCreditsToAdd,
                    2
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

    private function formatEmployeeNameForStorage(Employee $employee): string
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
