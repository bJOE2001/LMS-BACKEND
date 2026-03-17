<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        if (
            !Schema::hasTable('tblLeaveBalances')
            || !Schema::hasTable('tblEmployees')
            || !Schema::hasColumn('tblLeaveBalances', 'employee_id')
            || !Schema::hasColumn('tblEmployees', 'control_no')
        ) {
            return;
        }

        DB::statement("
UPDATE dbo.tblLeaveBalances
SET employee_id = LTRIM(RTRIM(employee_id))
WHERE employee_id IS NOT NULL;
");

        // Remove potential duplicates that collapse to the same canonical employee + leave type.
        DB::statement("
WITH mapped AS (
    SELECT
        lb.id,
        lb.leave_type_id,
        COALESCE(canonical.control_no, LTRIM(RTRIM(lb.employee_id))) AS canonical_employee_id,
        ROW_NUMBER() OVER (
            PARTITION BY COALESCE(canonical.control_no, LTRIM(RTRIM(lb.employee_id))), lb.leave_type_id
            ORDER BY ISNULL(lb.updated_at, lb.created_at) DESC, lb.id DESC
        ) AS rn
    FROM dbo.tblLeaveBalances AS lb
    OUTER APPLY (
        SELECT TOP 1 e.control_no
        FROM dbo.tblEmployees AS e
        WHERE TRY_CONVERT(BIGINT, e.control_no) = TRY_CONVERT(BIGINT, lb.employee_id)
        ORDER BY LEN(e.control_no) DESC, e.control_no DESC
    ) AS canonical
)
DELETE lb
FROM dbo.tblLeaveBalances AS lb
INNER JOIN mapped AS m ON m.id = lb.id
WHERE m.rn > 1;
");

        // Canonicalize employee_id to the exact employee control_no value.
        DB::statement("
UPDATE lb
SET lb.employee_id = mapped.canonical_employee_id
FROM dbo.tblLeaveBalances AS lb
CROSS APPLY (
    SELECT COALESCE(canonical.control_no, LTRIM(RTRIM(lb.employee_id))) AS canonical_employee_id
    FROM (SELECT 1 AS x) AS seed
    OUTER APPLY (
        SELECT TOP 1 e.control_no
        FROM dbo.tblEmployees AS e
        WHERE TRY_CONVERT(BIGINT, e.control_no) = TRY_CONVERT(BIGINT, lb.employee_id)
        ORDER BY LEN(e.control_no) DESC, e.control_no DESC
    ) AS canonical
) AS mapped
WHERE mapped.canonical_employee_id IS NOT NULL
  AND LTRIM(RTRIM(lb.employee_id)) <> mapped.canonical_employee_id;
");
    }

    public function down(): void
    {
        // Irreversible data canonicalization.
    }
};
