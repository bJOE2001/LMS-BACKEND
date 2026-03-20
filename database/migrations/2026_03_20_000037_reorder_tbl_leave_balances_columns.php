<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        if (DB::connection()->getDriverName() !== 'sqlsrv' || !Schema::hasTable('tblLeaveBalances')) {
            return;
        }

        $targetOrder = [
            'id',
            'employee_id',
            'employee_name',
            'leave_type_id',
            'leave_type_name',
            'balance',
            'initialized_at',
            'last_accrual_date',
            'year',
            'created_at',
            'updated_at',
        ];

        if (!$this->hasAllColumns('tblLeaveBalances', $targetOrder) || $this->columnOrder('tblLeaveBalances') === $targetOrder) {
            return;
        }

        $this->rebuildLeaveBalances($targetOrder);
    }

    public function down(): void
    {
        if (DB::connection()->getDriverName() !== 'sqlsrv' || !Schema::hasTable('tblLeaveBalances')) {
            return;
        }

        $legacyOrder = [
            'id',
            'employee_id',
            'leave_type_id',
            'balance',
            'initialized_at',
            'last_accrual_date',
            'year',
            'created_at',
            'updated_at',
            'employee_name',
            'leave_type_name',
        ];

        if (!$this->hasAllColumns('tblLeaveBalances', $legacyOrder) || $this->columnOrder('tblLeaveBalances') === $legacyOrder) {
            return;
        }

        $this->rebuildLeaveBalances($legacyOrder);
    }

    private function hasAllColumns(string $table, array $columns): bool
    {
        foreach ($columns as $column) {
            if (!Schema::hasColumn($table, $column)) {
                return false;
            }
        }

        return true;
    }

    private function columnOrder(string $table): array
    {
        return DB::table('INFORMATION_SCHEMA.COLUMNS')
            ->where('TABLE_NAME', $table)
            ->orderBy('ORDINAL_POSITION')
            ->pluck('COLUMN_NAME')
            ->map(static fn ($column) => (string) $column)
            ->all();
    }

    private function rebuildLeaveBalances(array $order): void
    {
        $definitions = [
            'id' => 'id BIGINT IDENTITY(1,1) NOT NULL',
            'employee_id' => 'employee_id NVARCHAR(255) NOT NULL',
            'employee_name' => 'employee_name NVARCHAR(255) NULL',
            'leave_type_id' => 'leave_type_id BIGINT NOT NULL',
            'leave_type_name' => 'leave_type_name NVARCHAR(255) NULL',
            'balance' => 'balance DECIMAL(8,2) NOT NULL CONSTRAINT DF_tblLeaveBalances_reordered_balance DEFAULT (0)',
            'initialized_at' => 'initialized_at DATETIME2 NULL',
            'last_accrual_date' => 'last_accrual_date DATE NULL',
            'year' => '[year] SMALLINT NULL',
            'created_at' => 'created_at DATETIME2 NULL',
            'updated_at' => 'updated_at DATETIME2 NULL',
        ];

        $tableColumns = implode(",\n        ", array_map(
            static fn (string $column): string => $definitions[$column],
            $order
        ));
        $orderedColumns = implode(', ', $order);

        DB::unprepared(<<<SQL
SET XACT_ABORT ON;
BEGIN TRY
    BEGIN TRANSACTION;

    IF OBJECT_ID('dbo.tblleavebalanceaccrualhistories_leave_balance_id_foreign', 'F') IS NOT NULL
        ALTER TABLE dbo.tblLeaveBalanceAccrualHistories DROP CONSTRAINT tblleavebalanceaccrualhistories_leave_balance_id_foreign;

    IF OBJECT_ID('dbo.tblleavebalances_employee_id_foreign', 'F') IS NOT NULL
        ALTER TABLE dbo.tblLeaveBalances DROP CONSTRAINT tblleavebalances_employee_id_foreign;

    IF OBJECT_ID('dbo.tblleavebalances_leave_type_id_foreign', 'F') IS NOT NULL
        ALTER TABLE dbo.tblLeaveBalances DROP CONSTRAINT tblleavebalances_leave_type_id_foreign;

    CREATE TABLE dbo.tblLeaveBalances_reordered_tmp (
        {$tableColumns},
        CONSTRAINT PK_tblLeaveBalances_reordered_tmp PRIMARY KEY CLUSTERED (id ASC)
    );

    SET IDENTITY_INSERT dbo.tblLeaveBalances_reordered_tmp ON;

    INSERT INTO dbo.tblLeaveBalances_reordered_tmp ({$orderedColumns})
    SELECT {$orderedColumns}
    FROM dbo.tblLeaveBalances;

    SET IDENTITY_INSERT dbo.tblLeaveBalances_reordered_tmp OFF;

    EXEC sp_rename 'dbo.tblLeaveBalances', 'tblLeaveBalances_old_reorder';
    EXEC sp_rename 'dbo.tblLeaveBalances_reordered_tmp', 'tblLeaveBalances';

    DROP TABLE dbo.tblLeaveBalances_old_reorder;

    IF NOT EXISTS (
        SELECT 1
        FROM sys.indexes
        WHERE name = 'tblleavebalances_employee_id_leave_type_id_unique'
          AND object_id = OBJECT_ID('dbo.tblLeaveBalances')
    )
        CREATE UNIQUE INDEX tblleavebalances_employee_id_leave_type_id_unique
            ON dbo.tblLeaveBalances (employee_id, leave_type_id);

    IF OBJECT_ID('dbo.tblleavebalances_employee_id_foreign', 'F') IS NULL
        ALTER TABLE dbo.tblLeaveBalances
            ADD CONSTRAINT tblleavebalances_employee_id_foreign
            FOREIGN KEY (employee_id) REFERENCES dbo.tblEmployees(control_no)
            ON DELETE CASCADE;

    IF OBJECT_ID('dbo.tblleavebalances_leave_type_id_foreign', 'F') IS NULL
        ALTER TABLE dbo.tblLeaveBalances
            ADD CONSTRAINT tblleavebalances_leave_type_id_foreign
            FOREIGN KEY (leave_type_id) REFERENCES dbo.tblLeaveTypes(id)
            ON DELETE CASCADE;

    IF OBJECT_ID('dbo.tblLeaveBalanceAccrualHistories', 'U') IS NOT NULL
       AND COL_LENGTH('dbo.tblLeaveBalanceAccrualHistories', 'leave_balance_id') IS NOT NULL
       AND OBJECT_ID('dbo.tblleavebalanceaccrualhistories_leave_balance_id_foreign', 'F') IS NULL
    BEGIN
        ALTER TABLE dbo.tblLeaveBalanceAccrualHistories
            ADD CONSTRAINT tblleavebalanceaccrualhistories_leave_balance_id_foreign
            FOREIGN KEY (leave_balance_id) REFERENCES dbo.tblLeaveBalances(id)
            ON DELETE CASCADE;
    END

    COMMIT TRANSACTION;
END TRY
BEGIN CATCH
    IF @@TRANCOUNT > 0
        ROLLBACK TRANSACTION;
    THROW;
END CATCH;
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
    }
};

