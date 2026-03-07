<?php

namespace App\Http\Controllers;

use App\Models\Employee;
use App\Models\EmployeeAccount;
use App\Models\LeaveBalance;
use App\Models\LeaveType;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

/**
 * Leave balance initialization and queries.
 * LOCAL LMS_DB only.
 */
class LeaveBalanceController extends Controller
{
    /**
     * Get leave types available for initialization (ACCRUED + RESETTABLE).
     * Only shown to employees who have not yet initialized.
     */
    public function initializableTypes(Request $request): JsonResponse
    {
        $employee = $this->resolveEmployee($request);
        if ($employee instanceof JsonResponse) {
            return $employee;
        }

        if ($employee->leave_initialized) {
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
     * One-time initialization of leave balances.
     * Expects: { balances: { <leave_type_id>: <decimal_value>, ... } }
     */
    public function initialize(Request $request): JsonResponse
    {
        $employee = $this->resolveEmployee($request);
        if ($employee instanceof JsonResponse) {
            return $employee;
        }

        if ($employee->leave_initialized) {
            return response()->json([
                'message' => 'Leave balances already initialized. Cannot re-initialize.',
            ], 422);
        }

        // Double-check no balances exist
        if (LeaveBalance::where('employee_id', $employee->id)->exists()) {
            return response()->json([
                'message' => 'Leave balances already exist for this employee.',
            ], 422);
        }

        // Validate the balances payload
        $allowedTypeIds = LeaveType::whereIn('category', [
            LeaveType::CATEGORY_ACCRUED,
            LeaveType::CATEGORY_RESETTABLE,
        ])->pluck('id')->toArray();

        $request->validate([
            'balances' => ['required', 'array', 'min:1'],
        ]);

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
            // Enforce max_days limit for leave types that have one
            $leaveType = LeaveType::find((int) $typeId);
            if ($leaveType && $leaveType->max_days !== null && (float) $value > (float) $leaveType->max_days) {
                $errors["balances.{$typeId}"] = ["Balance cannot exceed {$leaveType->max_days} for {$leaveType->name}."];
            }
        }

        // Ensure every allowed type has a value
        foreach ($allowedTypeIds as $id) {
            if (!array_key_exists((string) $id, $balances) && !array_key_exists($id, $balances)) {
                $errors["balances.{$id}"] = ['This leave type balance is required.'];
            }
        }

        if (!empty($errors)) {
            return response()->json([
                'message' => 'Validation failed.',
                'errors' => $errors,
            ], 422);
        }

        DB::transaction(function () use ($employee, $balances) {
            $now = now();
            $year = $now->year;

            foreach ($balances as $typeId => $value) {
                LeaveBalance::create([
                    'employee_id' => $employee->id,
                    'leave_type_id' => (int) $typeId,
                    'balance' => (float) $value,
                    'initialized_at' => $now,
                    'last_accrual_date' => null,
                    'year' => $year,
                ]);
            }

            $employee->update(['leave_initialized' => true]);
        });

        return response()->json([
            'message' => 'Leave balances initialized successfully.',
        ]);
    }

    /**
     * Resolve the Employee model from the authenticated EmployeeAccount.
     */
    private function resolveEmployee(Request $request): Employee|JsonResponse
    {
        $account = $request->user();
        if (!$account instanceof EmployeeAccount) {
            return response()->json(['message' => 'Only employee accounts can access this endpoint.'], 403);
        }

        $employee = Employee::find($account->employee_id);
        if (!$employee) {
            return response()->json(['message' => 'Employee record not found.'], 404);
        }

        return $employee;
    }
}
