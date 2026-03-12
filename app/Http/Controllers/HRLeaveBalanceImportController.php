<?php

namespace App\Http\Controllers;

use App\Models\Employee;
use App\Models\HRAccount;
use App\Models\LeaveBalance;
use App\Models\LeaveBalanceImportLog;
use App\Models\LeaveType;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * HR-only: Import employee leave balances from CSV.
 * LOCAL LMS_DB only. No connection to pmis2003 or BIOASD.
 */
class HRLeaveBalanceImportController extends Controller
{
    private const EXPECTED_HEADERS = ['employee_id', 'leave_type', 'balance'];

    /**
     * HR-only: manually set a single employee leave balance.
     */
    public function store(Request $request): JsonResponse
    {
        $user = $request->user();
        if (!$user instanceof HRAccount) {
            return response()->json(['message' => 'Only HR accounts can add leave balances.'], 403);
        }

        $validated = $request->validate([
            'employee_id' => ['required', 'string', 'max:50', 'regex:/^\d+$/', 'exists:tblEmployees,control_no'],
            'leave_type_id' => ['required', 'integer', 'exists:tblLeaveTypes,id'],
            'balance' => ['required', 'numeric', 'min:0'],
        ]);

        $employeeId = trim((string) $validated['employee_id']);
        $leaveTypeId = (int) $validated['leave_type_id'];
        $balance = round((float) $validated['balance'], 2);

        $employee = Employee::findByControlNo($employeeId);
        if (!$employee) {
            return response()->json(['message' => 'Employee not found.'], 422);
        }

        $leaveType = LeaveType::query()->find($leaveTypeId);
        if (!$leaveType) {
            return response()->json(['message' => 'Leave type not found.'], 422);
        }

        if (!(bool) $leaveType->is_credit_based) {
            return response()->json(['message' => 'Only credit-based leave types can have balances.'], 422);
        }

        $year = (int) now()->format('Y');

        $leaveBalance = LeaveBalance::updateOrCreate(
            [
                'employee_id' => $employee->control_no,
                'leave_type_id' => $leaveType->id,
            ],
            [
                'balance' => $balance,
                'year' => $year,
                'initialized_at' => now(),
            ]
        );

        return response()->json([
            'message' => 'Leave credits saved successfully.',
            'leave_balance' => [
                'id' => (int) $leaveBalance->id,
                'employee_id' => (string) $leaveBalance->employee_id,
                'employee_name' => trim("{$employee->firstname} {$employee->surname}"),
                'leave_type_id' => (int) $leaveBalance->leave_type_id,
                'leave_type_name' => $leaveType->name,
                'balance' => (float) $leaveBalance->balance,
                'year' => (int) $leaveBalance->year,
                'updated_at' => $leaveBalance->updated_at?->toIso8601String(),
            ],
        ]);
    }

    /**
     * Import leave balances from uploaded CSV.
     *
     * Expected CSV format (flexible):
     *   employee_id,leave_type,balance
     *   17,Vacation Leave,12.50
     *   17,Sick Leave,8.00
     *
     * - employee_id: employees.control_no
     * - leave_type: leave_types.name (exact match)
     * - balance: numeric >= 0
     */
    public function import(Request $request): JsonResponse
    {
        $user = $request->user();
        if (!$user instanceof HRAccount) {
            return response()->json(['message' => 'Only HR accounts can import leave balances.'], 403);
        }

        $request->validate([
            'file' => ['required', 'file', 'mimes:csv,txt', 'max:2048'],
        ]);

        $file = $request->file('file');
        $filename = $file->getClientOriginalName();

        $parseResult = $this->parseCsv($file);
        if (isset($parseResult['error'])) {
            return response()->json([
                'message' => 'Invalid CSV',
                'error' => $parseResult['error'],
            ], 422);
        }

        $rows = $parseResult['rows'];
        $totalRecords = count($rows);

        if ($totalRecords === 0) {
            return response()->json([
                'message' => 'No data rows found in CSV.',
                'total_records' => 0,
                'successful_records' => 0,
                'failed_records' => 0,
                'errors' => [],
            ], 422);
        }

        $year = (int) now()->format('Y');
        $errors = [];
        $successCount = 0;

        try {
            DB::transaction(function () use ($rows, $year, $user, $filename, $totalRecords, &$errors, &$successCount): void {
                $leaveTypesByName = LeaveType::all()->keyBy('name');

                foreach ($rows as $rowIndex => $row) {
                    $oneBasedRow = $rowIndex + 2; // 1-based, +1 for header
                    $employeeId = isset($row['employee_id']) ? trim((string) $row['employee_id']) : '';
                    $leaveTypeName = isset($row['leave_type']) ? trim((string) $row['leave_type']) : '';
                    $balanceRaw = isset($row['balance']) ? trim((string) $row['balance']) : '';

                    // 1) Validate employee exists (by control_no)
                    $employee = $employeeId !== '' ? Employee::findByControlNo($employeeId) : null;
                    if (!$employee) {
                        $errors[] = [
                            'row' => $oneBasedRow,
                            'value' => $employeeId ?: '(empty)',
                            'message' => $employeeId === '' ? 'employee_id is required.' : "Employee not found for employee_id: {$employeeId}.",
                        ];
                        Log::channel('single')->warning('HR leave balance import: skip row (employee not found)', [
                            'row' => $oneBasedRow,
                            'employee_id' => $employeeId,
                        ]);
                        continue;
                    }

                    // 2) Validate leave_type exists
                    $leaveType = $leaveTypesByName->get($leaveTypeName);
                    if (!$leaveType) {
                        $errors[] = [
                            'row' => $oneBasedRow,
                            'value' => $leaveTypeName ?: '(empty)',
                            'message' => $leaveTypeName === '' ? 'leave_type is required.' : "Leave type not found: {$leaveTypeName}.",
                        ];
                        Log::channel('single')->warning('HR leave balance import: skip row (leave type not found)', [
                            'row' => $oneBasedRow,
                            'leave_type' => $leaveTypeName,
                        ]);
                        continue;
                    }

                    // 3) Validate balance >= 0
                    if ($balanceRaw === '' || !is_numeric($balanceRaw)) {
                        $errors[] = [
                            'row' => $oneBasedRow,
                            'value' => $balanceRaw ?: '(empty)',
                            'message' => 'balance must be a number.',
                        ];
                        continue;
                    }
                    $balance = (float) $balanceRaw;
                    if ($balance < 0) {
                        $errors[] = [
                            'row' => $oneBasedRow,
                            'value' => $balanceRaw,
                            'message' => 'balance must be >= 0.',
                        ];
                        continue;
                    }

                    // 4) Update or create leave_balances
                    LeaveBalance::updateOrCreate(
                        [
                            'employee_id' => $employee->control_no,
                            'leave_type_id' => $leaveType->id,
                        ],
                        [
                            'balance' => $balance,
                            'year' => $year,
                            'initialized_at' => now(),
                        ]
                    );
                    $successCount++;
                }

                LeaveBalanceImportLog::create([
                    'hr_id' => $user->id,
                    'filename' => $filename,
                    'total_records' => $totalRecords,
                    'successful_records' => $successCount,
                    'failed_records' => $totalRecords - $successCount,
                ]);
            });
        } catch (\Throwable $e) {
            Log::channel('single')->error('HR leave balance import: transaction failed', [
                'message' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            return response()->json([
                'message' => 'Import failed due to an unexpected error.',
                'error' => config('app.debug') ? $e->getMessage() : null,
            ], 500);
        }

        $failedCount = $totalRecords - $successCount;
        $log = LeaveBalanceImportLog::where('hr_id', $user->id)
            ->where('filename', $filename)
            ->orderByDesc('id')
            ->first();

        return response()->json([
            'message' => 'Import completed.',
            'total_records' => $totalRecords,
            'successful_records' => $successCount,
            'failed_records' => $failedCount,
            'import_log_id' => $log?->id,
            'errors' => $errors,
        ], 200);
    }

    /**
     * Parse CSV file. Returns ['rows' => array of associative rows] or ['error' => string].
     */
    private function parseCsv($file): array
    {
        $path = $file->getRealPath();
        $handle = fopen($path, 'r');
        if ($handle === false) {
            return ['error' => 'Could not open file.'];
        }

        $headerRow = fgetcsv($handle);
        if ($headerRow === false) {
            fclose($handle);
            return ['error' => 'CSV is empty.'];
        }

        $headerRow = array_map(function ($cell) {
            return strtolower(trim(str_replace("\xEF\xBB\xBF", '', $cell)));
        }, $headerRow);
        $expected = array_map('strtolower', self::EXPECTED_HEADERS);
        foreach ($expected as $col) {
            if (!in_array($col, $headerRow, true)) {
                fclose($handle);
                return ['error' => "Missing required column: {$col}. Expected columns: " . implode(', ', self::EXPECTED_HEADERS) . '.'];
            }
        }

        $rows = [];
        while (($data = fgetcsv($handle)) !== false) {
            $assoc = [];
            foreach ($headerRow as $i => $key) {
                $assoc[$key] = $data[$i] ?? '';
            }
            if (array_filter($assoc, fn($v) => trim((string) $v) !== '') !== []) {
                $rows[] = $assoc;
            }
        }
        fclose($handle);

        return ['rows' => $rows];
    }
}
