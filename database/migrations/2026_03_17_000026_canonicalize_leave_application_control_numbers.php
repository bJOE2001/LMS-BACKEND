<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        if (
            !Schema::hasTable('tblLeaveApplications')
            || !Schema::hasTable('tblEmployees')
            || !Schema::hasColumn('tblLeaveApplications', 'erms_control_no')
            || !Schema::hasColumn('tblEmployees', 'control_no')
        ) {
            return;
        }

        DB::statement("
IF EXISTS (SELECT 1 FROM sys.indexes WHERE object_id = OBJECT_ID('dbo.tblLeaveApplications') AND name = 'IX_tblLeaveApplications_erms_control_no_status')
    DROP INDEX IX_tblLeaveApplications_erms_control_no_status ON dbo.tblLeaveApplications;
");

        DB::statement("
IF EXISTS (SELECT 1 FROM sys.indexes WHERE object_id = OBJECT_ID('dbo.tblLeaveApplications') AND name = 'IX_tblLeaveApplications_erms_control_no_created_at')
    DROP INDEX IX_tblLeaveApplications_erms_control_no_created_at ON dbo.tblLeaveApplications;
");

        DB::statement("
ALTER TABLE dbo.tblLeaveApplications
ALTER COLUMN erms_control_no NVARCHAR(255) NULL;
");

        DB::statement("
UPDATE dbo.tblLeaveApplications
SET erms_control_no = LTRIM(RTRIM(erms_control_no))
WHERE erms_control_no IS NOT NULL;
");

        // Canonicalize to the exact employee control_no string (preserving leading zeros).
        DB::statement("
UPDATE la
SET la.erms_control_no = canonical.control_no
FROM dbo.tblLeaveApplications AS la
OUTER APPLY (
    SELECT TOP 1 e.control_no
    FROM dbo.tblEmployees AS e
    WHERE TRY_CONVERT(BIGINT, e.control_no) = TRY_CONVERT(BIGINT, la.erms_control_no)
    ORDER BY LEN(e.control_no) DESC, e.control_no DESC
) AS canonical
WHERE la.erms_control_no IS NOT NULL
  AND canonical.control_no IS NOT NULL
  AND la.erms_control_no <> canonical.control_no;
");

        DB::statement("
IF NOT EXISTS (SELECT 1 FROM sys.indexes WHERE object_id = OBJECT_ID('dbo.tblLeaveApplications') AND name = 'IX_tblLeaveApplications_erms_control_no_status')
    CREATE INDEX IX_tblLeaveApplications_erms_control_no_status
        ON dbo.tblLeaveApplications (erms_control_no, status);
");

        DB::statement("
IF NOT EXISTS (SELECT 1 FROM sys.indexes WHERE object_id = OBJECT_ID('dbo.tblLeaveApplications') AND name = 'IX_tblLeaveApplications_erms_control_no_created_at')
    CREATE INDEX IX_tblLeaveApplications_erms_control_no_created_at
        ON dbo.tblLeaveApplications (erms_control_no, created_at);
");
    }

    public function down(): void
    {
        if (!Schema::hasTable('tblLeaveApplications') || !Schema::hasColumn('tblLeaveApplications', 'erms_control_no')) {
            return;
        }

        DB::statement("
IF EXISTS (SELECT 1 FROM sys.indexes WHERE object_id = OBJECT_ID('dbo.tblLeaveApplications') AND name = 'IX_tblLeaveApplications_erms_control_no_status')
    DROP INDEX IX_tblLeaveApplications_erms_control_no_status ON dbo.tblLeaveApplications;
");

        DB::statement("
IF EXISTS (SELECT 1 FROM sys.indexes WHERE object_id = OBJECT_ID('dbo.tblLeaveApplications') AND name = 'IX_tblLeaveApplications_erms_control_no_created_at')
    DROP INDEX IX_tblLeaveApplications_erms_control_no_created_at ON dbo.tblLeaveApplications;
");

        DB::statement("
UPDATE dbo.tblLeaveApplications
SET erms_control_no = TRY_CONVERT(INT, erms_control_no)
WHERE erms_control_no IS NOT NULL;
");

        DB::statement("
ALTER TABLE dbo.tblLeaveApplications
ALTER COLUMN erms_control_no INT NULL;
");

        DB::statement("
IF NOT EXISTS (SELECT 1 FROM sys.indexes WHERE object_id = OBJECT_ID('dbo.tblLeaveApplications') AND name = 'IX_tblLeaveApplications_erms_control_no_status')
    CREATE INDEX IX_tblLeaveApplications_erms_control_no_status
        ON dbo.tblLeaveApplications (erms_control_no, status);
");

        DB::statement("
IF NOT EXISTS (SELECT 1 FROM sys.indexes WHERE object_id = OBJECT_ID('dbo.tblLeaveApplications') AND name = 'IX_tblLeaveApplications_erms_control_no_created_at')
    CREATE INDEX IX_tblLeaveApplications_erms_control_no_created_at
        ON dbo.tblLeaveApplications (erms_control_no, created_at);
");
    }
};
