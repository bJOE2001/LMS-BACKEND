<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    private const TABLE = 'tblLeaveBalanceCreditHistories';
    private const UNIQUE_INDEX = 'UX_tblLeaveBalanceCreditHistories_balance_date_source';
    private const FK_NAME = 'tblleavebalancecredithistories_leave_balance_id_foreign';

    public function up(): void
    {
        if (
            DB::connection()->getDriverName() !== 'sqlsrv'
            || !Schema::hasTable(self::TABLE)
            || !Schema::hasTable('tblLeaveBalances')
        ) {
            return;
        }

        DB::unprepared(<<<'SQL'
IF OBJECT_ID('dbo.tblLeaveBalanceCreditHistories_tmp_reorder', 'U') IS NOT NULL
    DROP TABLE dbo.tblLeaveBalanceCreditHistories_tmp_reorder;

IF EXISTS (
    SELECT 1
    FROM sys.foreign_keys
    WHERE parent_object_id = OBJECT_ID('dbo.tblLeaveBalanceCreditHistories')
      AND name = 'tblleavebalancecredithistories_leave_balance_id_foreign'
)
    ALTER TABLE dbo.tblLeaveBalanceCreditHistories
        DROP CONSTRAINT tblleavebalancecredithistories_leave_balance_id_foreign;

IF EXISTS (
    SELECT 1
    FROM sys.indexes
    WHERE object_id = OBJECT_ID('dbo.tblLeaveBalanceCreditHistories')
      AND name = 'UX_tblLeaveBalanceCreditHistories_balance_date_source'
)
    DROP INDEX UX_tblLeaveBalanceCreditHistories_balance_date_source
    ON dbo.tblLeaveBalanceCreditHistories;

CREATE TABLE dbo.tblLeaveBalanceCreditHistories_tmp_reorder (
    id BIGINT IDENTITY(1,1) NOT NULL PRIMARY KEY,
    leave_balance_id BIGINT NOT NULL,
    employee_name NVARCHAR(255) NULL,
    leave_type_name NVARCHAR(255) NULL,
    credits_added DECIMAL(8,2) NOT NULL,
    accrual_date DATE NOT NULL,
    source NVARCHAR(32) NOT NULL,
    created_at DATETIME2(0) NULL,
    updated_at DATETIME2(0) NULL
);

SET IDENTITY_INSERT dbo.tblLeaveBalanceCreditHistories_tmp_reorder ON;

INSERT INTO dbo.tblLeaveBalanceCreditHistories_tmp_reorder
    (id, leave_balance_id, employee_name, leave_type_name, credits_added, accrual_date, source, created_at, updated_at)
SELECT
    id,
    leave_balance_id,
    employee_name,
    leave_type_name,
    credits_added,
    accrual_date,
    source,
    created_at,
    updated_at
FROM dbo.tblLeaveBalanceCreditHistories;

SET IDENTITY_INSERT dbo.tblLeaveBalanceCreditHistories_tmp_reorder OFF;

DROP TABLE dbo.tblLeaveBalanceCreditHistories;

EXEC sp_rename
    'dbo.tblLeaveBalanceCreditHistories_tmp_reorder',
    'tblLeaveBalanceCreditHistories';

CREATE UNIQUE INDEX UX_tblLeaveBalanceCreditHistories_balance_date_source
    ON dbo.tblLeaveBalanceCreditHistories (leave_balance_id, accrual_date, source);

ALTER TABLE dbo.tblLeaveBalanceCreditHistories
    WITH CHECK ADD CONSTRAINT tblleavebalancecredithistories_leave_balance_id_foreign
    FOREIGN KEY (leave_balance_id)
    REFERENCES dbo.tblLeaveBalances (id)
    ON DELETE CASCADE;

ALTER TABLE dbo.tblLeaveBalanceCreditHistories
    CHECK CONSTRAINT tblleavebalancecredithistories_leave_balance_id_foreign;
SQL);
    }

    public function down(): void
    {
        if (
            DB::connection()->getDriverName() !== 'sqlsrv'
            || !Schema::hasTable(self::TABLE)
            || !Schema::hasTable('tblLeaveBalances')
        ) {
            return;
        }

        DB::unprepared(<<<'SQL'
IF OBJECT_ID('dbo.tblLeaveBalanceCreditHistories_tmp_reorder_down', 'U') IS NOT NULL
    DROP TABLE dbo.tblLeaveBalanceCreditHistories_tmp_reorder_down;

IF EXISTS (
    SELECT 1
    FROM sys.foreign_keys
    WHERE parent_object_id = OBJECT_ID('dbo.tblLeaveBalanceCreditHistories')
      AND name = 'tblleavebalancecredithistories_leave_balance_id_foreign'
)
    ALTER TABLE dbo.tblLeaveBalanceCreditHistories
        DROP CONSTRAINT tblleavebalancecredithistories_leave_balance_id_foreign;

IF EXISTS (
    SELECT 1
    FROM sys.indexes
    WHERE object_id = OBJECT_ID('dbo.tblLeaveBalanceCreditHistories')
      AND name = 'UX_tblLeaveBalanceCreditHistories_balance_date_source'
)
    DROP INDEX UX_tblLeaveBalanceCreditHistories_balance_date_source
    ON dbo.tblLeaveBalanceCreditHistories;

CREATE TABLE dbo.tblLeaveBalanceCreditHistories_tmp_reorder_down (
    id BIGINT IDENTITY(1,1) NOT NULL PRIMARY KEY,
    leave_balance_id BIGINT NOT NULL,
    credits_added DECIMAL(8,2) NOT NULL,
    accrual_date DATE NOT NULL,
    source NVARCHAR(32) NOT NULL,
    created_at DATETIME2(0) NULL,
    updated_at DATETIME2(0) NULL,
    employee_name NVARCHAR(255) NULL,
    leave_type_name NVARCHAR(255) NULL
);

SET IDENTITY_INSERT dbo.tblLeaveBalanceCreditHistories_tmp_reorder_down ON;

INSERT INTO dbo.tblLeaveBalanceCreditHistories_tmp_reorder_down
    (id, leave_balance_id, credits_added, accrual_date, source, created_at, updated_at, employee_name, leave_type_name)
SELECT
    id,
    leave_balance_id,
    credits_added,
    accrual_date,
    source,
    created_at,
    updated_at,
    employee_name,
    leave_type_name
FROM dbo.tblLeaveBalanceCreditHistories;

SET IDENTITY_INSERT dbo.tblLeaveBalanceCreditHistories_tmp_reorder_down OFF;

DROP TABLE dbo.tblLeaveBalanceCreditHistories;

EXEC sp_rename
    'dbo.tblLeaveBalanceCreditHistories_tmp_reorder_down',
    'tblLeaveBalanceCreditHistories';

CREATE UNIQUE INDEX UX_tblLeaveBalanceCreditHistories_balance_date_source
    ON dbo.tblLeaveBalanceCreditHistories (leave_balance_id, accrual_date, source);

ALTER TABLE dbo.tblLeaveBalanceCreditHistories
    WITH CHECK ADD CONSTRAINT tblleavebalancecredithistories_leave_balance_id_foreign
    FOREIGN KEY (leave_balance_id)
    REFERENCES dbo.tblLeaveBalances (id)
    ON DELETE CASCADE;

ALTER TABLE dbo.tblLeaveBalanceCreditHistories
    CHECK CONSTRAINT tblleavebalancecredithistories_leave_balance_id_foreign;
SQL);
    }
};

