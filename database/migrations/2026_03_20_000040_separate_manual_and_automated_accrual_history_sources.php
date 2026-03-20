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

        // Normalize empty sources first.
        DB::unprepared(<<<'SQL'
UPDATE dbo.tblLeaveBalanceAccrualHistories
SET source = 'AUTOMATED'
WHERE source IS NULL OR LTRIM(RTRIM(source)) = '';
SQL);

        // Allow one row per (balance, date, source) so manual and automated
        // credits can coexist on the same date without collapsing into MIXED.
        DB::unprepared(<<<'SQL'
IF EXISTS (
    SELECT 1
    FROM sys.indexes
    WHERE object_id = OBJECT_ID('dbo.tblLeaveBalanceAccrualHistories')
      AND name = 'tblleavebalanceaccrualhistories_leave_balance_id_accrual_date_unique'
)
    DROP INDEX tblleavebalanceaccrualhistories_leave_balance_id_accrual_date_unique
    ON dbo.tblLeaveBalanceAccrualHistories;

IF NOT EXISTS (
    SELECT 1
    FROM sys.indexes
    WHERE object_id = OBJECT_ID('dbo.tblLeaveBalanceAccrualHistories')
      AND name = 'UX_tblLeaveBalanceAccrualHistories_balance_date_source'
)
    CREATE UNIQUE INDEX UX_tblLeaveBalanceAccrualHistories_balance_date_source
        ON dbo.tblLeaveBalanceAccrualHistories (leave_balance_id, accrual_date, source);
SQL);

        // Split legacy MIXED rows into separate AUTOMATED and HR_ADD rows.
        DB::unprepared(<<<'SQL'
;WITH mixed_rows AS (
    SELECT
        h.id,
        h.leave_balance_id,
        h.accrual_date,
        CAST(h.credits_added AS DECIMAL(8,2)) AS total_credits,
        CAST(
            CASE
                WHEN COALESCE(lt.accrual_rate, 0) <= 0 THEN 0
                WHEN COALESCE(lt.accrual_rate, 0) >= h.credits_added THEN h.credits_added
                ELSE lt.accrual_rate
            END
            AS DECIMAL(8,2)
        ) AS automated_credits
    FROM dbo.tblLeaveBalanceAccrualHistories AS h
    INNER JOIN dbo.tblLeaveBalances AS lb
        ON lb.id = h.leave_balance_id
    LEFT JOIN dbo.tblLeaveTypes AS lt
        ON lt.id = lb.leave_type_id
    WHERE UPPER(LTRIM(RTRIM(COALESCE(h.source, '')))) = 'MIXED'
)
UPDATE h
SET
    h.credits_added = m.automated_credits,
    h.source = CASE WHEN m.automated_credits > 0 THEN 'AUTOMATED' ELSE 'HR_ADD' END
FROM dbo.tblLeaveBalanceAccrualHistories AS h
INNER JOIN mixed_rows AS m
    ON m.id = h.id;

;WITH mixed_rows AS (
    SELECT
        h.id,
        h.leave_balance_id,
        h.accrual_date,
        CAST(h.credits_added AS DECIMAL(8,2)) AS total_credits,
        CAST(
            CASE
                WHEN COALESCE(lt.accrual_rate, 0) <= 0 THEN 0
                WHEN COALESCE(lt.accrual_rate, 0) >= h.credits_added THEN h.credits_added
                ELSE lt.accrual_rate
            END
            AS DECIMAL(8,2)
        ) AS automated_credits,
        h.created_at,
        h.updated_at
    FROM dbo.tblLeaveBalanceAccrualHistories AS h
    INNER JOIN dbo.tblLeaveBalances AS lb
        ON lb.id = h.leave_balance_id
    LEFT JOIN dbo.tblLeaveTypes AS lt
        ON lt.id = lb.leave_type_id
    WHERE UPPER(LTRIM(RTRIM(COALESCE(h.source, '')))) = 'MIXED'
)
INSERT INTO dbo.tblLeaveBalanceAccrualHistories
    (leave_balance_id, credits_added, accrual_date, source, created_at, updated_at)
SELECT
    m.leave_balance_id,
    CAST(m.total_credits - m.automated_credits AS DECIMAL(8,2)) AS manual_credits,
    m.accrual_date,
    'HR_ADD',
    m.created_at,
    m.updated_at
FROM mixed_rows AS m
WHERE CAST(m.total_credits - m.automated_credits AS DECIMAL(8,2)) > 0
  AND NOT EXISTS (
      SELECT 1
      FROM dbo.tblLeaveBalanceAccrualHistories AS x
      WHERE x.leave_balance_id = m.leave_balance_id
        AND x.accrual_date = m.accrual_date
        AND UPPER(LTRIM(RTRIM(COALESCE(x.source, '')))) = 'HR_ADD'
  );
SQL);
    }

    public function down(): void
    {
        if (
            DB::connection()->getDriverName() !== 'sqlsrv'
            || !Schema::hasTable('tblLeaveBalanceAccrualHistories')
        ) {
            return;
        }

        DB::unprepared(<<<'SQL'
IF EXISTS (
    SELECT 1
    FROM sys.indexes
    WHERE object_id = OBJECT_ID('dbo.tblLeaveBalanceAccrualHistories')
      AND name = 'UX_tblLeaveBalanceAccrualHistories_balance_date_source'
)
    DROP INDEX UX_tblLeaveBalanceAccrualHistories_balance_date_source
    ON dbo.tblLeaveBalanceAccrualHistories;

IF NOT EXISTS (
    SELECT 1
    FROM sys.indexes
    WHERE object_id = OBJECT_ID('dbo.tblLeaveBalanceAccrualHistories')
      AND name = 'tblleavebalanceaccrualhistories_leave_balance_id_accrual_date_unique'
)
AND NOT EXISTS (
    SELECT 1
    FROM dbo.tblLeaveBalanceAccrualHistories
    GROUP BY leave_balance_id, accrual_date
    HAVING COUNT(*) > 1
)
    CREATE UNIQUE INDEX tblleavebalanceaccrualhistories_leave_balance_id_accrual_date_unique
        ON dbo.tblLeaveBalanceAccrualHistories (leave_balance_id, accrual_date);
SQL);
    }
};

