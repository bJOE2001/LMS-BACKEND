<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        if (DB::connection()->getDriverName() !== 'sqlsrv' || !Schema::hasTable('tblEmployees')) {
            return;
        }

        $targetOrder = [
            'control_no',
            'surname',
            'firstname',
            'middlename',
            'office',
            'status',
            'designation',
            'rate_mon',
            'birth_date',
            'created_at',
            'updated_at',
        ];

        if ($this->columnOrder('tblEmployees') === $targetOrder) {
            return;
        }

        $this->rebuildEmployees($targetOrder);
    }

    public function down(): void
    {
        if (DB::connection()->getDriverName() !== 'sqlsrv' || !Schema::hasTable('tblEmployees')) {
            return;
        }

        $legacyOrder = [
            'control_no',
            'surname',
            'firstname',
            'middlename',
            'office',
            'status',
            'designation',
            'rate_mon',
            'created_at',
            'updated_at',
            'birth_date',
        ];

        if ($this->columnOrder('tblEmployees') === $legacyOrder) {
            return;
        }

        $this->rebuildEmployees($legacyOrder);
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

    private function rebuildEmployees(array $order): void
    {
        $definitions = [
            'control_no' => 'control_no NVARCHAR(255) NOT NULL',
            'surname' => 'surname NVARCHAR(255) NULL',
            'firstname' => 'firstname NVARCHAR(255) NULL',
            'middlename' => 'middlename NVARCHAR(255) NULL',
            'office' => 'office NVARCHAR(255) NULL',
            'status' => 'status NVARCHAR(255) NULL',
            'designation' => 'designation NVARCHAR(255) NULL',
            'rate_mon' => 'rate_mon DECIMAL(10,2) NULL',
            'birth_date' => 'birth_date DATE NULL',
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

    IF OBJECT_ID('dbo.tblleavebalances_employee_id_foreign', 'F') IS NOT NULL
        ALTER TABLE dbo.tblLeaveBalances DROP CONSTRAINT tblleavebalances_employee_id_foreign;

    CREATE TABLE dbo.tblEmployees_reordered_tmp (
        {$tableColumns},
        CONSTRAINT PK_tblEmployees_reordered_tmp PRIMARY KEY CLUSTERED (control_no ASC)
    );

    INSERT INTO dbo.tblEmployees_reordered_tmp ({$orderedColumns})
    SELECT {$orderedColumns}
    FROM dbo.tblEmployees;

    EXEC sp_rename 'dbo.tblEmployees', 'tblEmployees_old_reorder';
    EXEC sp_rename 'dbo.tblEmployees_reordered_tmp', 'tblEmployees';

    DROP TABLE dbo.tblEmployees_old_reorder;

    IF NOT EXISTS (SELECT 1 FROM sys.indexes WHERE name = 'tblemployees_office_index' AND object_id = OBJECT_ID('dbo.tblEmployees'))
        CREATE INDEX tblemployees_office_index ON dbo.tblEmployees (office);

    IF NOT EXISTS (SELECT 1 FROM sys.indexes WHERE name = 'tblemployees_status_index' AND object_id = OBJECT_ID('dbo.tblEmployees'))
        CREATE INDEX tblemployees_status_index ON dbo.tblEmployees (status);

    IF NOT EXISTS (SELECT 1 FROM sys.indexes WHERE name = 'IX_tblEmployees_office_control_no' AND object_id = OBJECT_ID('dbo.tblEmployees'))
        CREATE INDEX IX_tblEmployees_office_control_no ON dbo.tblEmployees (office, control_no);

    IF OBJECT_ID('dbo.tblLeaveBalances', 'U') IS NOT NULL
       AND COL_LENGTH('dbo.tblLeaveBalances', 'employee_id') IS NOT NULL
       AND OBJECT_ID('dbo.tblleavebalances_employee_id_foreign', 'F') IS NULL
    BEGIN
        ALTER TABLE dbo.tblLeaveBalances
            ADD CONSTRAINT tblleavebalances_employee_id_foreign
            FOREIGN KEY (employee_id) REFERENCES dbo.tblEmployees(control_no)
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
    }
};
