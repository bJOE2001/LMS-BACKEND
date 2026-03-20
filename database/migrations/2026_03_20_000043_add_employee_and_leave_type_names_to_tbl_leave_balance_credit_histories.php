<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    private function resolveHistoryTableName(): ?string
    {
        if (Schema::hasTable('tblLeaveBalanceCreditHistories')) {
            return 'tblLeaveBalanceCreditHistories';
        }

        if (Schema::hasTable('tblLeaveBalanceAccrualHistories')) {
            return 'tblLeaveBalanceAccrualHistories';
        }

        return null;
    }

    public function up(): void
    {
        $historyTable = $this->resolveHistoryTableName();
        if ($historyTable === null) {
            return;
        }

        Schema::table($historyTable, function (Blueprint $table) use ($historyTable): void {
            if (!Schema::hasColumn($historyTable, 'employee_name')) {
                $table->string('employee_name')->nullable();
            }

            if (!Schema::hasColumn($historyTable, 'leave_type_name')) {
                $table->string('leave_type_name')->nullable();
            }
        });

        if (
            DB::connection()->getDriverName() !== 'sqlsrv'
            || !Schema::hasTable('tblLeaveBalances')
            || !Schema::hasTable('tblLeaveTypes')
        ) {
            return;
        }

        $qualifiedHistoryTable = $historyTable === 'tblLeaveBalanceCreditHistories'
            ? 'dbo.tblLeaveBalanceCreditHistories'
            : 'dbo.tblLeaveBalanceAccrualHistories';

        DB::unprepared(<<<SQL
UPDATE h
SET
    h.employee_name = COALESCE(
        NULLIF(LTRIM(RTRIM(h.employee_name)), ''),
        NULLIF(LTRIM(RTRIM(lb.employee_name)), ''),
        NULLIF(
            LTRIM(RTRIM(
                CONCAT(
                    COALESCE(NULLIF(LTRIM(RTRIM(e.surname)), ''), ''),
                    CASE
                        WHEN NULLIF(LTRIM(RTRIM(e.surname)), '') IS NOT NULL
                            AND (
                                NULLIF(LTRIM(RTRIM(e.firstname)), '') IS NOT NULL
                                OR NULLIF(LTRIM(RTRIM(e.middlename)), '') IS NOT NULL
                            )
                        THEN ', '
                        ELSE ''
                    END,
                    COALESCE(NULLIF(LTRIM(RTRIM(e.firstname)), ''), ''),
                    CASE
                        WHEN NULLIF(LTRIM(RTRIM(e.middlename)), '') IS NOT NULL
                        THEN CONCAT(' ', LTRIM(RTRIM(e.middlename)))
                        ELSE ''
                    END
                )
            )),
            ''
        )
    ),
    h.leave_type_name = COALESCE(
        NULLIF(LTRIM(RTRIM(h.leave_type_name)), ''),
        NULLIF(LTRIM(RTRIM(lb.leave_type_name)), ''),
        NULLIF(LTRIM(RTRIM(lt.name)), '')
    )
FROM {$qualifiedHistoryTable} AS h
LEFT JOIN dbo.tblLeaveBalances AS lb
    ON lb.id = h.leave_balance_id
LEFT JOIN dbo.tblEmployees AS e
    ON e.control_no = lb.employee_id
LEFT JOIN dbo.tblLeaveTypes AS lt
    ON lt.id = lb.leave_type_id;
SQL);
    }

    public function down(): void
    {
        $historyTable = $this->resolveHistoryTableName();
        if ($historyTable === null) {
            return;
        }

        $hasEmployeeName = Schema::hasColumn($historyTable, 'employee_name');
        $hasLeaveTypeName = Schema::hasColumn($historyTable, 'leave_type_name');
        if (!$hasEmployeeName && !$hasLeaveTypeName) {
            return;
        }

        Schema::table($historyTable, function (Blueprint $table) use ($hasEmployeeName, $hasLeaveTypeName): void {
            if ($hasEmployeeName) {
                $table->dropColumn('employee_name');
            }
            if ($hasLeaveTypeName) {
                $table->dropColumn('leave_type_name');
            }
        });
    }
};

