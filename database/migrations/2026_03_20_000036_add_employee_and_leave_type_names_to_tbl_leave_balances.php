<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        if (!Schema::hasTable('tblLeaveBalances')) {
            return;
        }

        Schema::table('tblLeaveBalances', function (Blueprint $table): void {
            if (!Schema::hasColumn('tblLeaveBalances', 'employee_name')) {
                $table->string('employee_name')->nullable();
            }

            if (!Schema::hasColumn('tblLeaveBalances', 'leave_type_name')) {
                $table->string('leave_type_name')->nullable();
            }
        });

        if (
            DB::connection()->getDriverName() !== 'sqlsrv'
            || !Schema::hasTable('tblEmployees')
            || !Schema::hasTable('tblLeaveTypes')
        ) {
            return;
        }

        DB::unprepared(<<<'SQL'
UPDATE lb
SET
    lb.employee_name = NULLIF(
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
    ),
    lb.leave_type_name = NULLIF(LTRIM(RTRIM(lt.name)), '')
FROM dbo.tblLeaveBalances AS lb
LEFT JOIN dbo.tblEmployees AS e
    ON e.control_no = lb.employee_id
LEFT JOIN dbo.tblLeaveTypes AS lt
    ON lt.id = lb.leave_type_id;
SQL);

        DB::unprepared(<<<'SQL'
CREATE OR ALTER TRIGGER dbo.trg_tblLeaveBalances_sync_names
ON dbo.tblLeaveBalances
AFTER INSERT, UPDATE
AS
BEGIN
    SET NOCOUNT ON;

    IF TRIGGER_NESTLEVEL() > 1
        RETURN;

    UPDATE lb
    SET
        lb.employee_name = NULLIF(
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
        ),
        lb.leave_type_name = NULLIF(LTRIM(RTRIM(lt.name)), '')
    FROM dbo.tblLeaveBalances AS lb
    INNER JOIN inserted AS i
        ON i.id = lb.id
    LEFT JOIN dbo.tblEmployees AS e
        ON e.control_no = lb.employee_id
    LEFT JOIN dbo.tblLeaveTypes AS lt
        ON lt.id = lb.leave_type_id;
END;
SQL);

        DB::unprepared(<<<'SQL'
CREATE OR ALTER TRIGGER dbo.trg_tblEmployees_sync_leave_balance_employee_name
ON dbo.tblEmployees
AFTER INSERT, UPDATE
AS
BEGIN
    SET NOCOUNT ON;

    UPDATE lb
    SET lb.employee_name = NULLIF(
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
    FROM dbo.tblLeaveBalances AS lb
    INNER JOIN inserted AS i
        ON i.control_no = lb.employee_id
    INNER JOIN dbo.tblEmployees AS e
        ON e.control_no = i.control_no;
END;
SQL);

        DB::unprepared(<<<'SQL'
CREATE OR ALTER TRIGGER dbo.trg_tblLeaveTypes_sync_leave_balance_leave_type_name
ON dbo.tblLeaveTypes
AFTER INSERT, UPDATE
AS
BEGIN
    SET NOCOUNT ON;

    UPDATE lb
    SET lb.leave_type_name = NULLIF(LTRIM(RTRIM(lt.name)), '')
    FROM dbo.tblLeaveBalances AS lb
    INNER JOIN inserted AS i
        ON i.id = lb.leave_type_id
    INNER JOIN dbo.tblLeaveTypes AS lt
        ON lt.id = i.id;
END;
SQL);
    }

    public function down(): void
    {
        if (DB::connection()->getDriverName() === 'sqlsrv') {
            DB::unprepared(<<<'SQL'
IF OBJECT_ID('dbo.trg_tblLeaveBalances_sync_names', 'TR') IS NOT NULL
    DROP TRIGGER dbo.trg_tblLeaveBalances_sync_names;

IF OBJECT_ID('dbo.trg_tblEmployees_sync_leave_balance_employee_name', 'TR') IS NOT NULL
    DROP TRIGGER dbo.trg_tblEmployees_sync_leave_balance_employee_name;

IF OBJECT_ID('dbo.trg_tblLeaveTypes_sync_leave_balance_leave_type_name', 'TR') IS NOT NULL
    DROP TRIGGER dbo.trg_tblLeaveTypes_sync_leave_balance_leave_type_name;
SQL);
        }

        if (!Schema::hasTable('tblLeaveBalances')) {
            return;
        }

        $hasEmployeeName = Schema::hasColumn('tblLeaveBalances', 'employee_name');
        $hasLeaveTypeName = Schema::hasColumn('tblLeaveBalances', 'leave_type_name');

        if (!$hasEmployeeName && !$hasLeaveTypeName) {
            return;
        }

        Schema::table('tblLeaveBalances', function (Blueprint $table) use ($hasEmployeeName, $hasLeaveTypeName): void {
            if ($hasEmployeeName) {
                $table->dropColumn('employee_name');
            }
            if ($hasLeaveTypeName) {
                $table->dropColumn('leave_type_name');
            }
        });
    }
};
