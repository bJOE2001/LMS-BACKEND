<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration {
    public function up(): void
    {
        // Drop FK on employee_id if it exists (we only keep logical ERMS reference).
        DB::statement("
            DECLARE @fkName NVARCHAR(255);
            DECLARE @sql NVARCHAR(MAX);
            SELECT @fkName = fk.name
            FROM sys.foreign_keys fk
            INNER JOIN sys.foreign_key_columns fkc ON fk.object_id = fkc.constraint_object_id
            INNER JOIN sys.columns c ON c.object_id = fkc.parent_object_id AND c.column_id = fkc.parent_column_id
            WHERE OBJECT_NAME(fk.parent_object_id) = 'tblLeaveApplications'
              AND c.name = 'employee_id';
            IF @fkName IS NOT NULL
            BEGIN
                SET @sql = N'ALTER TABLE tblLeaveApplications DROP CONSTRAINT ' + QUOTENAME(@fkName);
                EXEC sp_executesql @sql;
            END
        ");

        // Rename employee_id -> erms_control_no.
        DB::statement("
            IF COL_LENGTH('tblLeaveApplications', 'employee_id') IS NOT NULL
               AND COL_LENGTH('tblLeaveApplications', 'erms_control_no') IS NULL
            BEGIN
                EXEC sp_rename 'tblLeaveApplications.employee_id', 'erms_control_no', 'COLUMN';
            END
        ");

        // Convert to INT and allow NULL; non-numeric values become NULL.
        DB::statement("
            IF COL_LENGTH('tblLeaveApplications', 'erms_control_no') IS NOT NULL
            BEGIN
                UPDATE tblLeaveApplications
                SET erms_control_no = TRY_CONVERT(INT, erms_control_no)
                WHERE erms_control_no IS NOT NULL;

                ALTER TABLE tblLeaveApplications ALTER COLUMN erms_control_no INT NULL;
            END
        ");

        // Recreate common lookup index for leave applications.
        DB::statement("
            IF COL_LENGTH('tblLeaveApplications', 'erms_control_no') IS NOT NULL
               AND NOT EXISTS (
                    SELECT 1
                    FROM sys.indexes i
                    WHERE i.object_id = OBJECT_ID('tblLeaveApplications')
                      AND i.name = 'IX_tblLeaveApplications_erms_control_no_status'
               )
            BEGIN
                CREATE INDEX IX_tblLeaveApplications_erms_control_no_status
                ON tblLeaveApplications (erms_control_no, status);
            END
        ");

        // Ensure tblEmployees(control_no) index exists for employee lookup performance.
        DB::statement("
            IF COL_LENGTH('tblEmployees', 'control_no') IS NOT NULL
               AND NOT EXISTS (
                    SELECT 1
                    FROM sys.indexes i
                    INNER JOIN sys.index_columns ic ON i.object_id = ic.object_id AND i.index_id = ic.index_id
                    INNER JOIN sys.columns c ON c.object_id = ic.object_id AND c.column_id = ic.column_id
                    WHERE i.object_id = OBJECT_ID('tblEmployees')
                      AND c.name = 'control_no'
                      AND ic.key_ordinal = 1
               )
            BEGIN
                CREATE INDEX IX_tblEmployees_control_no ON tblEmployees (control_no);
            END
        ");
    }

    public function down(): void
    {
        DB::statement("
            IF EXISTS (
                SELECT 1 FROM sys.indexes
                WHERE object_id = OBJECT_ID('tblLeaveApplications')
                  AND name = 'IX_tblLeaveApplications_erms_control_no_status'
            )
            BEGIN
                DROP INDEX IX_tblLeaveApplications_erms_control_no_status ON tblLeaveApplications;
            END
        ");

        DB::statement("
            IF COL_LENGTH('tblLeaveApplications', 'erms_control_no') IS NOT NULL
            BEGIN
                ALTER TABLE tblLeaveApplications ALTER COLUMN erms_control_no NVARCHAR(255) NULL;
                EXEC sp_rename 'tblLeaveApplications.erms_control_no', 'employee_id', 'COLUMN';
            END
        ");
    }
};
