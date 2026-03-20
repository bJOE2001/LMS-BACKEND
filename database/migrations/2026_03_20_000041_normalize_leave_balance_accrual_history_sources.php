<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        if (
            DB::connection()->getDriverName() !== 'sqlsrv'
            || !Schema::hasTable('tblLeaveBalanceAccrualHistories')
        ) {
            return;
        }

        DB::unprepared(<<<'SQL'
IF OBJECT_ID('tempdb..#normalized_sources') IS NOT NULL
    DROP TABLE #normalized_sources;

CREATE TABLE #normalized_sources (
    leave_balance_id BIGINT NOT NULL,
    credits_added DECIMAL(8,2) NOT NULL,
    accrual_date DATE NOT NULL,
    source NVARCHAR(32) NOT NULL,
    created_at DATETIME2 NULL,
    updated_at DATETIME2 NULL
);

INSERT INTO #normalized_sources
    (leave_balance_id, credits_added, accrual_date, source, created_at, updated_at)
SELECT
    h.leave_balance_id,
    CAST(SUM(CAST(h.credits_added AS DECIMAL(8,2))) AS DECIMAL(8,2)) AS credits_added,
    h.accrual_date,
    CASE
        WHEN UPPER(LTRIM(RTRIM(COALESCE(h.source, '')))) LIKE 'HR_ADD:%' THEN LEFT(LTRIM(RTRIM(h.source)), 32)
        WHEN UPPER(LTRIM(RTRIM(COALESCE(h.source, '')))) IN ('HR_ADD', 'HR_IMPORT') THEN 'HR_ADD'
        ELSE 'AUTOMATED'
    END AS normalized_source,
    MIN(h.created_at) AS created_at,
    MAX(h.updated_at) AS updated_at
FROM dbo.tblLeaveBalanceAccrualHistories AS h
GROUP BY
    h.leave_balance_id,
    h.accrual_date,
    CASE
        WHEN UPPER(LTRIM(RTRIM(COALESCE(h.source, '')))) LIKE 'HR_ADD:%' THEN LEFT(LTRIM(RTRIM(h.source)), 32)
        WHEN UPPER(LTRIM(RTRIM(COALESCE(h.source, '')))) IN ('HR_ADD', 'HR_IMPORT') THEN 'HR_ADD'
        ELSE 'AUTOMATED'
    END
HAVING CAST(SUM(CAST(h.credits_added AS DECIMAL(8,2))) AS DECIMAL(8,2)) > 0;

DELETE FROM dbo.tblLeaveBalanceAccrualHistories;

INSERT INTO dbo.tblLeaveBalanceAccrualHistories
    (leave_balance_id, credits_added, accrual_date, source, created_at, updated_at)
SELECT
    n.leave_balance_id,
    n.credits_added,
    n.accrual_date,
    n.source,
    n.created_at,
    n.updated_at
FROM #normalized_sources AS n;
SQL);
    }

    public function down(): void
    {
        // Intentionally left blank because normalization is lossy by design.
    }
};

