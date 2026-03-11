<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        if (DB::connection()->getDriverName() !== 'sqlsrv') {
            return;
        }

        if ($this->columnOrder('tblDepartmentHeads') === [
            'id',
            'department_id',
            'control_no',
            'surname',
            'firstname',
            'middlename',
            'full_name',
            'office',
            'status',
            'position',
            'designation',
            'rate_mon',
            'created_at',
            'updated_at',
        ]) {
            // Already ordered consistently.
        } elseif (Schema::hasTable('tblDepartmentHeads')) {
            $this->rebuildDepartmentHeads([
                'id',
                'department_id',
                'control_no',
                'surname',
                'firstname',
                'middlename',
                'full_name',
                'office',
                'status',
                'position',
                'designation',
                'rate_mon',
                'created_at',
                'updated_at',
            ], false);
        }

        if ($this->columnOrder('tblLeaveApplications') === [
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
        ]) {
            // Already ordered consistently.
        } elseif (Schema::hasTable('tblLeaveApplications')) {
            $this->rebuildLeaveApplications([
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
            ], false);
        }
    }

    public function down(): void
    {
        if (DB::connection()->getDriverName() !== 'sqlsrv') {
            return;
        }

        if ($this->columnOrder('tblDepartmentHeads') === [
            'id',
            'department_id',
            'control_no',
            'surname',
            'firstname',
            'middlename',
            'full_name',
            'office',
            'status',
            'position',
            'designation',
            'rate_mon',
            'created_at',
            'updated_at',
        ]) {
            $this->rebuildDepartmentHeads([
                'id',
                'department_id',
                'full_name',
                'position',
                'created_at',
                'updated_at',
                'control_no',
                'surname',
                'firstname',
                'middlename',
                'office',
                'status',
                'designation',
                'rate_mon',
            ], true);
        }

        if ($this->columnOrder('tblLeaveApplications') === [
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
        ]) {
            $this->rebuildLeaveApplications([
                'id',
                'applicant_admin_id',
                'erms_control_no',
                'leave_type_id',
                'start_date',
                'end_date',
                'total_days',
                'reason',
                'status',
                'admin_id',
                'hr_id',
                'admin_approved_at',
                'hr_approved_at',
                'remarks',
                'created_at',
                'updated_at',
                'selected_dates',
                'is_monetization',
                'equivalent_amount',
                'commutation',
            ], true);
        }
    }

    private function columnOrder(string $table): array
    {
        if (!Schema::hasTable($table)) {
            return [];
        }

        return DB::table('INFORMATION_SCHEMA.COLUMNS')
            ->where('TABLE_NAME', $table)
            ->orderBy('ORDINAL_POSITION')
            ->pluck('COLUMN_NAME')
            ->map(static fn ($column) => (string) $column)
            ->all();
    }

    private function rebuildDepartmentHeads(array $order, bool $legacyOrder): void
    {
        $selectColumns = implode(', ', $order);
        $insertColumns = implode(', ', $order);
        $tableDefinition = $legacyOrder
            ? <<<SQL
        id BIGINT IDENTITY(1,1) NOT NULL,
        department_id BIGINT NOT NULL,
        full_name NVARCHAR(255) NOT NULL,
        position NVARCHAR(255) NULL,
        created_at DATETIME2 NULL,
        updated_at DATETIME2 NULL,
        control_no NVARCHAR(255) NULL,
        surname NVARCHAR(255) NULL,
        firstname NVARCHAR(255) NULL,
        middlename NVARCHAR(255) NULL,
        office NVARCHAR(255) NULL,
        status NVARCHAR(255) NULL,
        designation NVARCHAR(255) NULL,
        rate_mon DECIMAL(10,2) NULL,
SQL
            : <<<SQL
        id BIGINT IDENTITY(1,1) NOT NULL,
        department_id BIGINT NOT NULL,
        control_no NVARCHAR(255) NULL,
        surname NVARCHAR(255) NULL,
        firstname NVARCHAR(255) NULL,
        middlename NVARCHAR(255) NULL,
        full_name NVARCHAR(255) NOT NULL,
        office NVARCHAR(255) NULL,
        status NVARCHAR(255) NULL,
        position NVARCHAR(255) NULL,
        designation NVARCHAR(255) NULL,
        rate_mon DECIMAL(10,2) NULL,
        created_at DATETIME2 NULL,
        updated_at DATETIME2 NULL,
SQL;

        DB::unprepared(<<<SQL
SET XACT_ABORT ON;
BEGIN TRY
    BEGIN TRANSACTION;

    CREATE TABLE dbo.tblDepartmentHeads_reordered_tmp (
{$tableDefinition}
        CONSTRAINT PK_tblDepartmentHeads_reordered_tmp PRIMARY KEY CLUSTERED (id ASC)
    );

    SET IDENTITY_INSERT dbo.tblDepartmentHeads_reordered_tmp ON;

    INSERT INTO dbo.tblDepartmentHeads_reordered_tmp ({$insertColumns})
    SELECT {$selectColumns}
    FROM dbo.tblDepartmentHeads;

    SET IDENTITY_INSERT dbo.tblDepartmentHeads_reordered_tmp OFF;

    EXEC sp_rename 'dbo.tblDepartmentHeads', 'tblDepartmentHeads_old_reorder';
    EXEC sp_rename 'dbo.tblDepartmentHeads_reordered_tmp', 'tblDepartmentHeads';

    DROP TABLE dbo.tblDepartmentHeads_old_reorder;

    ALTER TABLE dbo.tblDepartmentHeads
        ADD CONSTRAINT tbldepartmentheads_department_id_unique UNIQUE (department_id);

    ALTER TABLE dbo.tblDepartmentHeads
        ADD CONSTRAINT tbldepartmentheads_department_id_foreign
        FOREIGN KEY (department_id) REFERENCES dbo.tblDepartments(id)
        ON DELETE CASCADE;

    COMMIT TRANSACTION;
END TRY
BEGIN CATCH
    IF @@TRANCOUNT > 0
        ROLLBACK TRANSACTION;
    THROW;
END CATCH;
SQL);
    }

    private function rebuildLeaveApplications(array $order, bool $legacyOrder): void
    {
        $selectColumns = implode(', ', $order);
        $insertColumns = implode(', ', $order);
        $tableDefinition = $legacyOrder
            ? <<<SQL
        id BIGINT IDENTITY(1,1) NOT NULL,
        applicant_admin_id BIGINT NULL,
        erms_control_no INT NULL,
        leave_type_id BIGINT NOT NULL,
        start_date DATE NULL,
        end_date DATE NULL,
        total_days DECIMAL(5,2) NOT NULL,
        reason NVARCHAR(MAX) NULL,
        status NVARCHAR(255) NOT NULL,
        admin_id BIGINT NULL,
        hr_id BIGINT NULL,
        admin_approved_at DATETIME2 NULL,
        hr_approved_at DATETIME2 NULL,
        remarks NVARCHAR(MAX) NULL,
        created_at DATETIME2 NULL,
        updated_at DATETIME2 NULL,
        selected_dates NVARCHAR(MAX) NULL,
        is_monetization BIT NOT NULL,
        equivalent_amount DECIMAL(12,2) NULL,
        commutation NVARCHAR(32) NULL,
SQL
            : <<<SQL
        id BIGINT IDENTITY(1,1) NOT NULL,
        applicant_admin_id BIGINT NULL,
        erms_control_no INT NULL,
        leave_type_id BIGINT NOT NULL,
        start_date DATE NULL,
        end_date DATE NULL,
        selected_dates NVARCHAR(MAX) NULL,
        total_days DECIMAL(5,2) NOT NULL,
        reason NVARCHAR(MAX) NULL,
        commutation NVARCHAR(32) NULL,
        is_monetization BIT NOT NULL,
        equivalent_amount DECIMAL(12,2) NULL,
        status NVARCHAR(255) NOT NULL,
        admin_id BIGINT NULL,
        admin_approved_at DATETIME2 NULL,
        hr_id BIGINT NULL,
        hr_approved_at DATETIME2 NULL,
        remarks NVARCHAR(MAX) NULL,
        created_at DATETIME2 NULL,
        updated_at DATETIME2 NULL,
SQL;

        DB::unprepared(<<<SQL
SET XACT_ABORT ON;
BEGIN TRY
    BEGIN TRANSACTION;

    IF OBJECT_ID('dbo.tblleaveapplicationlogs_leave_application_id_foreign', 'F') IS NOT NULL
        ALTER TABLE dbo.tblLeaveApplicationLogs DROP CONSTRAINT tblleaveapplicationlogs_leave_application_id_foreign;

    IF OBJECT_ID('dbo.tblnotifications_leave_application_id_foreign', 'F') IS NOT NULL
        ALTER TABLE dbo.tblNotifications DROP CONSTRAINT tblnotifications_leave_application_id_foreign;

    CREATE TABLE dbo.tblLeaveApplications_reordered_tmp (
{$tableDefinition}
        CONSTRAINT PK_tblLeaveApplications_reordered_tmp PRIMARY KEY CLUSTERED (id ASC)
    );

    SET IDENTITY_INSERT dbo.tblLeaveApplications_reordered_tmp ON;

    INSERT INTO dbo.tblLeaveApplications_reordered_tmp ({$insertColumns})
    SELECT {$selectColumns}
    FROM dbo.tblLeaveApplications;

    SET IDENTITY_INSERT dbo.tblLeaveApplications_reordered_tmp OFF;

    EXEC sp_rename 'dbo.tblLeaveApplications', 'tblLeaveApplications_old_reorder';
    EXEC sp_rename 'dbo.tblLeaveApplications_reordered_tmp', 'tblLeaveApplications';

    DROP TABLE dbo.tblLeaveApplications_old_reorder;

    ALTER TABLE dbo.tblLeaveApplications
        ADD CONSTRAINT DF_tblLeaveApplications_status DEFAULT (N'PENDING_ADMIN') FOR status;

    ALTER TABLE dbo.tblLeaveApplications
        ADD CONSTRAINT DF_tblLeaveApplications_is_monetization DEFAULT ((0)) FOR is_monetization;

    ALTER TABLE dbo.tblLeaveApplications
        ADD CONSTRAINT tblleaveapplications_applicant_admin_id_foreign
        FOREIGN KEY (applicant_admin_id) REFERENCES dbo.tblDepartmentAdmins(id)
        ON DELETE NO ACTION;

    ALTER TABLE dbo.tblLeaveApplications
        ADD CONSTRAINT FK_tblLeaveApplications_erms_control_no
        FOREIGN KEY (erms_control_no) REFERENCES dbo.tblEmployees(control_no_int)
        ON DELETE NO ACTION;

    ALTER TABLE dbo.tblLeaveApplications
        ADD CONSTRAINT tblleaveapplications_leave_type_id_foreign
        FOREIGN KEY (leave_type_id) REFERENCES dbo.tblLeaveTypes(id)
        ON DELETE CASCADE;

    ALTER TABLE dbo.tblLeaveApplications
        ADD CONSTRAINT FK_tblLeaveApplications_admin_id
        FOREIGN KEY (admin_id) REFERENCES dbo.tblDepartmentAdmins(id)
        ON DELETE SET NULL;

    ALTER TABLE dbo.tblLeaveApplications
        ADD CONSTRAINT FK_tblLeaveApplications_hr_id
        FOREIGN KEY (hr_id) REFERENCES dbo.tblHRAccounts(id)
        ON DELETE SET NULL;

    CREATE INDEX tblleaveapplications_applicant_admin_id_status_index
        ON dbo.tblLeaveApplications (applicant_admin_id, status);

    CREATE INDEX tblleaveapplications_status_index
        ON dbo.tblLeaveApplications (status);

    CREATE INDEX IX_tblLeaveApplications_erms_control_no_status
        ON dbo.tblLeaveApplications (erms_control_no, status);

    CREATE INDEX IX_tblLeaveApplications_status_created_at
        ON dbo.tblLeaveApplications (status, created_at);

    CREATE INDEX IX_tblLeaveApplications_erms_control_no_created_at
        ON dbo.tblLeaveApplications (erms_control_no, created_at);

    CREATE INDEX IX_tblLeaveApplications_leave_type_status
        ON dbo.tblLeaveApplications (leave_type_id, status);

    CREATE INDEX IX_tblLeaveApplications_status_hr_approved_at
        ON dbo.tblLeaveApplications (status, hr_approved_at);

    ALTER TABLE dbo.tblLeaveApplicationLogs
        ADD CONSTRAINT tblleaveapplicationlogs_leave_application_id_foreign
        FOREIGN KEY (leave_application_id) REFERENCES dbo.tblLeaveApplications(id)
        ON DELETE CASCADE;

    ALTER TABLE dbo.tblNotifications
        ADD CONSTRAINT tblnotifications_leave_application_id_foreign
        FOREIGN KEY (leave_application_id) REFERENCES dbo.tblLeaveApplications(id)
        ON DELETE SET NULL;

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
