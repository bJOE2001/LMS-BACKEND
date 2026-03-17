<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        if (
            !Schema::hasTable('tblAdminLeaveBalances')
            || !Schema::hasTable('tblDepartmentAdmins')
            || !Schema::hasTable('tblLeaveBalances')
        ) {
            return;
        }

        $adminBalances = DB::table('tblAdminLeaveBalances as alb')
            ->join('tblDepartmentAdmins as da', 'da.id', '=', 'alb.admin_id')
            ->select([
                'alb.id',
                'alb.leave_type_id',
                'alb.balance',
                'alb.initialized_at',
                'alb.year',
                'da.employee_control_no',
            ])
            ->orderBy('alb.id')
            ->get();

        $now = now();

        foreach ($adminBalances as $row) {
            $employeeControlNo = trim((string) ($row->employee_control_no ?? ''));
            if ($employeeControlNo === '') {
                continue;
            }

            $existing = DB::table('tblLeaveBalances')
                ->where('leave_type_id', (int) $row->leave_type_id)
                ->where(function ($query) use ($employeeControlNo): void {
                    $query->where('employee_id', $employeeControlNo)
                        ->orWhereRaw('TRY_CONVERT(INT, employee_id) = TRY_CONVERT(INT, ?)', [$employeeControlNo]);
                })
                ->exists();

            if ($existing) {
                continue;
            }

            DB::table('tblLeaveBalances')->insert([
                'employee_id' => $employeeControlNo,
                'leave_type_id' => (int) $row->leave_type_id,
                'balance' => (float) $row->balance,
                'initialized_at' => $row->initialized_at ?? $now,
                'year' => $row->year !== null ? (int) $row->year : (int) $now->year,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }

        if (Schema::hasColumn('tblDepartmentAdmins', 'leave_initialized')) {
            $departmentAdmins = DB::table('tblDepartmentAdmins')
                ->select(['id', 'employee_control_no'])
                ->get();

            foreach ($departmentAdmins as $admin) {
                $employeeControlNo = trim((string) ($admin->employee_control_no ?? ''));
                if ($employeeControlNo === '') {
                    DB::table('tblDepartmentAdmins')
                        ->where('id', $admin->id)
                        ->update(['leave_initialized' => false]);
                    continue;
                }

                $hasEmployeeBalances = DB::table('tblLeaveBalances')
                    ->where(function ($query) use ($employeeControlNo): void {
                        $query->where('employee_id', $employeeControlNo)
                            ->orWhereRaw('TRY_CONVERT(INT, employee_id) = TRY_CONVERT(INT, ?)', [$employeeControlNo]);
                    })
                    ->exists();

                DB::table('tblDepartmentAdmins')
                    ->where('id', $admin->id)
                    ->update(['leave_initialized' => $hasEmployeeBalances]);
            }
        }
    }

    public function down(): void
    {
        // No-op: this migration only backfills missing employee leave balances.
    }
};
