<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    private const TABLE = 'tblLeaveApplications';

    public function up(): void
    {
        if (DB::connection()->getDriverName() !== 'sqlsrv' || !Schema::hasTable(self::TABLE)) {
            return;
        }

        $targetOrder = [
            'id',
            'applicant_admin_id',
            'erms_control_no',
            'leave_type_id',
            'start_date',
            'end_date',
            'selected_dates',
            'total_days',
            'reason',
            'commutation',
            'is_monetization',
            'equivalent_amount',
            'status',
            'admin_id',
            'admin_approved_at',
            'hr_id',
            'hr_approved_at',
            'remarks',
            'created_at',
            'updated_at',
            'pay_mode',
            'selected_date_pay_status',
            'selected_date_coverage',
            'deductible_days',
            'medical_certificate_required',
            'medical_certificate_submitted',
            'medical_certificate_reference',
        ];

        if (
            !$this->hasAllColumns(self::TABLE, $targetOrder)
            || $this->columnOrder(self::TABLE) === $targetOrder
        ) {
            return;
        }

        $this->rebuildLeaveApplications($targetOrder, 'tblLeaveApplications_tmp_reorder');
    }

    public function down(): void
    {
        if (DB::connection()->getDriverName() !== 'sqlsrv' || !Schema::hasTable(self::TABLE)) {
            return;
        }

        $legacyOrder = [
            'id',
            'applicant_admin_id',
            'erms_control_no',
            'leave_type_id',
            'start_date',
            'end_date',
            'selected_dates',
            'selected_date_pay_status',
            'selected_date_coverage',
            'total_days',
            'deductible_days',
            'reason',
            'commutation',
            'pay_mode',
            'is_monetization',
            'equivalent_amount',
            'status',
            'admin_id',
            'admin_approved_at',
            'hr_id',
            'hr_approved_at',
            'remarks',
            'created_at',
            'updated_at',
            'medical_certificate_required',
            'medical_certificate_submitted',
            'medical_certificate_reference',
        ];

        if (
            !$this->hasAllColumns(self::TABLE, $legacyOrder)
            || $this->columnOrder(self::TABLE) === $legacyOrder
        ) {
            return;
        }

        $this->rebuildLeaveApplications($legacyOrder, 'tblLeaveApplications_tmp_reorder_down');
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
            ->map(static fn ($column): string => (string) $column)
            ->all();
    }

    private function rebuildLeaveApplications(array $order, string $tempTable): void
    {
        $definitions = [
            'id' => 'id BIGINT IDENTITY(1,1) NOT NULL',
            'applicant_admin_id' => 'applicant_admin_id BIGINT NULL',
            'erms_control_no' => 'erms_control_no INT NULL',
            'leave_type_id' => 'leave_type_id BIGINT NOT NULL',
            'start_date' => 'start_date DATE NULL',
            'end_date' => 'end_date DATE NULL',
            'selected_dates' => 'selected_dates NVARCHAR(MAX) NULL',
            'total_days' => 'total_days DECIMAL(5,2) NOT NULL',
            'reason' => 'reason NVARCHAR(MAX) NULL',
            'commutation' => 'commutation NVARCHAR(32) NULL',
            'is_monetization' => 'is_monetization BIT NOT NULL DEFAULT ((0))',
            'equivalent_amount' => 'equivalent_amount DECIMAL(12,2) NULL',
            'status' => "status NVARCHAR(255) NOT NULL DEFAULT (N'PENDING_ADMIN')",
            'admin_id' => 'admin_id BIGINT NULL',
            'admin_approved_at' => 'admin_approved_at DATETIME2 NULL',
            'hr_id' => 'hr_id BIGINT NULL',
            'hr_approved_at' => 'hr_approved_at DATETIME2 NULL',
            'remarks' => 'remarks NVARCHAR(MAX) NULL',
            'created_at' => 'created_at DATETIME2 NULL',
            'updated_at' => 'updated_at DATETIME2 NULL',
            'pay_mode' => "pay_mode NVARCHAR(8) NOT NULL DEFAULT (N'WP')",
            'selected_date_pay_status' => 'selected_date_pay_status NVARCHAR(MAX) NULL',
            'selected_date_coverage' => 'selected_date_coverage NVARCHAR(MAX) NULL',
            'deductible_days' => 'deductible_days DECIMAL(5,2) NULL',
            'medical_certificate_required' => 'medical_certificate_required BIT NOT NULL DEFAULT ((0))',
            'medical_certificate_submitted' => 'medical_certificate_submitted BIT NOT NULL DEFAULT ((0))',
            'medical_certificate_reference' => 'medical_certificate_reference NVARCHAR(500) NULL',
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

    IF OBJECT_ID('dbo.{$tempTable}', 'U') IS NOT NULL
        DROP TABLE dbo.{$tempTable};

    DECLARE @dropInboundSql NVARCHAR(MAX) = N'';
    SELECT @dropInboundSql = @dropInboundSql
        + N'ALTER TABLE '
        + QUOTENAME(OBJECT_SCHEMA_NAME(parent_object_id))
        + N'.'
        + QUOTENAME(OBJECT_NAME(parent_object_id))
        + N' DROP CONSTRAINT '
        + QUOTENAME(name)
        + N';'
    FROM sys.foreign_keys
    WHERE referenced_object_id = OBJECT_ID('dbo.tblLeaveApplications');

    IF @dropInboundSql <> N''
        EXEC sp_executesql @dropInboundSql;

    CREATE TABLE dbo.{$tempTable} (
        {$tableColumns},
        PRIMARY KEY CLUSTERED (id ASC)
    );

    SET IDENTITY_INSERT dbo.{$tempTable} ON;

    INSERT INTO dbo.{$tempTable} ({$orderedColumns})
    SELECT {$orderedColumns}
    FROM dbo.tblLeaveApplications;

    SET IDENTITY_INSERT dbo.{$tempTable} OFF;

    EXEC sp_rename 'dbo.tblLeaveApplications', 'tblLeaveApplications_old_reorder';
    EXEC sp_rename 'dbo.{$tempTable}', 'tblLeaveApplications';

    DROP TABLE dbo.tblLeaveApplications_old_reorder;

    IF OBJECT_ID('dbo.tblleaveapplications_applicant_admin_id_foreign', 'F') IS NULL
        ALTER TABLE dbo.tblLeaveApplications
            ADD CONSTRAINT tblleaveapplications_applicant_admin_id_foreign
            FOREIGN KEY (applicant_admin_id) REFERENCES dbo.tblDepartmentAdmins(id)
            ON DELETE NO ACTION;

    IF OBJECT_ID('dbo.tblleaveapplications_leave_type_id_foreign', 'F') IS NULL
        ALTER TABLE dbo.tblLeaveApplications
            ADD CONSTRAINT tblleaveapplications_leave_type_id_foreign
            FOREIGN KEY (leave_type_id) REFERENCES dbo.tblLeaveTypes(id)
            ON DELETE CASCADE;

    IF OBJECT_ID('dbo.FK_tblLeaveApplications_admin_id', 'F') IS NULL
        ALTER TABLE dbo.tblLeaveApplications
            ADD CONSTRAINT FK_tblLeaveApplications_admin_id
            FOREIGN KEY (admin_id) REFERENCES dbo.tblDepartmentAdmins(id)
            ON DELETE SET NULL;

    IF OBJECT_ID('dbo.FK_tblLeaveApplications_hr_id', 'F') IS NULL
        ALTER TABLE dbo.tblLeaveApplications
            ADD CONSTRAINT FK_tblLeaveApplications_hr_id
            FOREIGN KEY (hr_id) REFERENCES dbo.tblHRAccounts(id)
            ON DELETE SET NULL;

    IF NOT EXISTS (
        SELECT 1 FROM sys.indexes
        WHERE object_id = OBJECT_ID('dbo.tblLeaveApplications')
          AND name = 'tblleaveapplications_applicant_admin_id_status_index'
    )
        CREATE INDEX tblleaveapplications_applicant_admin_id_status_index
            ON dbo.tblLeaveApplications (applicant_admin_id, status);

    IF NOT EXISTS (
        SELECT 1 FROM sys.indexes
        WHERE object_id = OBJECT_ID('dbo.tblLeaveApplications')
          AND name = 'tblleaveapplications_status_index'
    )
        CREATE INDEX tblleaveapplications_status_index
            ON dbo.tblLeaveApplications (status);

    IF NOT EXISTS (
        SELECT 1 FROM sys.indexes
        WHERE object_id = OBJECT_ID('dbo.tblLeaveApplications')
          AND name = 'IX_tblLeaveApplications_erms_control_no_status'
    )
        CREATE INDEX IX_tblLeaveApplications_erms_control_no_status
            ON dbo.tblLeaveApplications (erms_control_no, status);

    IF NOT EXISTS (
        SELECT 1 FROM sys.indexes
        WHERE object_id = OBJECT_ID('dbo.tblLeaveApplications')
          AND name = 'IX_tblLeaveApplications_status_created_at'
    )
        CREATE INDEX IX_tblLeaveApplications_status_created_at
            ON dbo.tblLeaveApplications (status, created_at);

    IF NOT EXISTS (
        SELECT 1 FROM sys.indexes
        WHERE object_id = OBJECT_ID('dbo.tblLeaveApplications')
          AND name = 'IX_tblLeaveApplications_erms_control_no_created_at'
    )
        CREATE INDEX IX_tblLeaveApplications_erms_control_no_created_at
            ON dbo.tblLeaveApplications (erms_control_no, created_at);

    IF NOT EXISTS (
        SELECT 1 FROM sys.indexes
        WHERE object_id = OBJECT_ID('dbo.tblLeaveApplications')
          AND name = 'IX_tblLeaveApplications_leave_type_status'
    )
        CREATE INDEX IX_tblLeaveApplications_leave_type_status
            ON dbo.tblLeaveApplications (leave_type_id, status);

    IF NOT EXISTS (
        SELECT 1 FROM sys.indexes
        WHERE object_id = OBJECT_ID('dbo.tblLeaveApplications')
          AND name = 'IX_tblLeaveApplications_status_hr_approved_at'
    )
        CREATE INDEX IX_tblLeaveApplications_status_hr_approved_at
            ON dbo.tblLeaveApplications (status, hr_approved_at);

    IF OBJECT_ID('dbo.tblLeaveApplicationLogs', 'U') IS NOT NULL
       AND COL_LENGTH('dbo.tblLeaveApplicationLogs', 'leave_application_id') IS NOT NULL
       AND OBJECT_ID('dbo.tblleaveapplicationlogs_leave_application_id_foreign', 'F') IS NULL
    BEGIN
        ALTER TABLE dbo.tblLeaveApplicationLogs
            ADD CONSTRAINT tblleaveapplicationlogs_leave_application_id_foreign
            FOREIGN KEY (leave_application_id) REFERENCES dbo.tblLeaveApplications(id)
            ON DELETE CASCADE;
    END

    IF OBJECT_ID('dbo.tblNotifications', 'U') IS NOT NULL
       AND COL_LENGTH('dbo.tblNotifications', 'leave_application_id') IS NOT NULL
       AND OBJECT_ID('dbo.tblnotifications_leave_application_id_foreign', 'F') IS NULL
    BEGIN
        ALTER TABLE dbo.tblNotifications
            ADD CONSTRAINT tblnotifications_leave_application_id_foreign
            FOREIGN KEY (leave_application_id) REFERENCES dbo.tblLeaveApplications(id)
            ON DELETE SET NULL;
    END

    IF OBJECT_ID('dbo.tblLeaveApplicationUpdateRequests', 'U') IS NOT NULL
       AND COL_LENGTH('dbo.tblLeaveApplicationUpdateRequests', 'leave_application_id') IS NOT NULL
       AND OBJECT_ID('dbo.tblleaveapplicationupdaterequests_leave_application_id_foreign', 'F') IS NULL
    BEGIN
        ALTER TABLE dbo.tblLeaveApplicationUpdateRequests
            ADD CONSTRAINT tblleaveapplicationupdaterequests_leave_application_id_foreign
            FOREIGN KEY (leave_application_id) REFERENCES dbo.tblLeaveApplications(id)
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

