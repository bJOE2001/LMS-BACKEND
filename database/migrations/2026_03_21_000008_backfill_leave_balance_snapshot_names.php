<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $employeeLookup = [];

        DB::table('tblEmployees')
            ->select(['control_no', 'surname', 'firstname', 'middlename'])
            ->orderBy('control_no')
            ->get()
            ->each(function (object $employee) use (&$employeeLookup): void {
                $rawControlNo = trim((string) ($employee->control_no ?? ''));
                if ($rawControlNo === '') {
                    return;
                }

                $employeeName = $this->formatEmployeeName(
                    (string) ($employee->surname ?? ''),
                    (string) ($employee->firstname ?? ''),
                    (string) ($employee->middlename ?? '')
                );

                $employeeLookup[$rawControlNo] = $employeeName;

                $normalizedControlNo = $this->normalizeControlNo($rawControlNo);
                if ($normalizedControlNo !== null) {
                    $employeeLookup[$normalizedControlNo] = $employeeName;
                }
            });

        $leaveTypeLookup = DB::table('tblLeaveTypes')
            ->select(['id', 'name'])
            ->pluck('name', 'id')
            ->map(fn (mixed $name): string => trim((string) $name))
            ->all();

        DB::table('tblLeaveBalances')
            ->select(['id', 'employee_id', 'employee_name', 'leave_type_id', 'leave_type_name'])
            ->orderBy('id')
            ->chunkById(500, function ($balances) use ($employeeLookup, $leaveTypeLookup): void {
                foreach ($balances as $balance) {
                    $updates = [];

                    if (trim((string) ($balance->employee_name ?? '')) === '') {
                        $rawControlNo = trim((string) ($balance->employee_id ?? ''));
                        $resolvedEmployeeName = trim((string) ($employeeLookup[$rawControlNo] ?? ''));

                        if ($resolvedEmployeeName === '') {
                            $normalizedControlNo = $this->normalizeControlNo($rawControlNo);
                            if ($normalizedControlNo !== null) {
                                $resolvedEmployeeName = trim((string) ($employeeLookup[$normalizedControlNo] ?? ''));
                            }
                        }

                        if ($resolvedEmployeeName !== '') {
                            $updates['employee_name'] = $resolvedEmployeeName;
                        }
                    }

                    if (trim((string) ($balance->leave_type_name ?? '')) === '') {
                        $resolvedLeaveTypeName = trim((string) ($leaveTypeLookup[(int) ($balance->leave_type_id ?? 0)] ?? ''));
                        if ($resolvedLeaveTypeName !== '') {
                            $updates['leave_type_name'] = $resolvedLeaveTypeName;
                        }
                    }

                    if ($updates !== []) {
                        DB::table('tblLeaveBalances')
                            ->where('id', $balance->id)
                            ->update($updates);
                    }
                }
            }, 'id');
    }

    public function down(): void
    {
        // No-op: this migration backfills snapshot names only.
    }

    private function formatEmployeeName(string $surname, string $firstname, string $middlename): string
    {
        $surname = trim($surname);
        $firstname = trim($firstname);
        $middlename = trim($middlename);

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

    private function normalizeControlNo(?string $value): ?string
    {
        $raw = trim((string) ($value ?? ''));
        if ($raw === '' || !preg_match('/^\d+$/', $raw)) {
            return null;
        }

        $normalized = ltrim($raw, '0');
        return $normalized !== '' ? $normalized : '0';
    }
};
