<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    private string $oldTable = 'tblLeaveBalanceAccrualHistories';
    private string $newTable = 'tblLeaveBalanceCreditHistories';

    public function up(): void
    {
        if (Schema::hasTable($this->newTable)) {
            return;
        }

        if (!Schema::hasTable($this->oldTable)) {
            return;
        }

        Schema::rename($this->oldTable, $this->newTable);
        $this->renameSqlServerObjectsForNewTable();
    }

    public function down(): void
    {
        if (Schema::hasTable($this->oldTable)) {
            return;
        }

        if (!Schema::hasTable($this->newTable)) {
            return;
        }

        Schema::rename($this->newTable, $this->oldTable);
        $this->renameSqlServerObjectsForOldTable();
    }

    private function renameSqlServerObjectsForNewTable(): void
    {
        if (DB::connection()->getDriverName() !== 'sqlsrv') {
            return;
        }

        DB::unprepared(<<<'SQL'
IF EXISTS (
    SELECT 1
    FROM sys.indexes
    WHERE object_id = OBJECT_ID('dbo.tblLeaveBalanceCreditHistories')
      AND name = 'UX_tblLeaveBalanceAccrualHistories_balance_date_source'
)
AND NOT EXISTS (
    SELECT 1
    FROM sys.indexes
    WHERE object_id = OBJECT_ID('dbo.tblLeaveBalanceCreditHistories')
      AND name = 'UX_tblLeaveBalanceCreditHistories_balance_date_source'
)
    EXEC sp_rename
        'dbo.tblLeaveBalanceCreditHistories.UX_tblLeaveBalanceAccrualHistories_balance_date_source',
        'UX_tblLeaveBalanceCreditHistories_balance_date_source',
        'INDEX';

IF EXISTS (
    SELECT 1
    FROM sys.indexes
    WHERE object_id = OBJECT_ID('dbo.tblLeaveBalanceCreditHistories')
      AND name = 'tblleavebalanceaccrualhistories_leave_balance_id_accrual_date_unique'
)
AND NOT EXISTS (
    SELECT 1
    FROM sys.indexes
    WHERE object_id = OBJECT_ID('dbo.tblLeaveBalanceCreditHistories')
      AND name = 'tblleavebalancecredithistories_leave_balance_id_accrual_date_unique'
)
    EXEC sp_rename
        'dbo.tblLeaveBalanceCreditHistories.tblleavebalanceaccrualhistories_leave_balance_id_accrual_date_unique',
        'tblleavebalancecredithistories_leave_balance_id_accrual_date_unique',
        'INDEX';

IF EXISTS (
    SELECT 1
    FROM sys.foreign_keys
    WHERE parent_object_id = OBJECT_ID('dbo.tblLeaveBalanceCreditHistories')
      AND name = 'tblleavebalanceaccrualhistories_leave_balance_id_foreign'
)
AND NOT EXISTS (
    SELECT 1
    FROM sys.foreign_keys
    WHERE parent_object_id = OBJECT_ID('dbo.tblLeaveBalanceCreditHistories')
      AND name = 'tblleavebalancecredithistories_leave_balance_id_foreign'
)
    EXEC sp_rename
        'dbo.tblleavebalanceaccrualhistories_leave_balance_id_foreign',
        'tblleavebalancecredithistories_leave_balance_id_foreign',
        'OBJECT';
SQL);
    }

    private function renameSqlServerObjectsForOldTable(): void
    {
        if (DB::connection()->getDriverName() !== 'sqlsrv') {
            return;
        }

        DB::unprepared(<<<'SQL'
IF EXISTS (
    SELECT 1
    FROM sys.indexes
    WHERE object_id = OBJECT_ID('dbo.tblLeaveBalanceAccrualHistories')
      AND name = 'UX_tblLeaveBalanceCreditHistories_balance_date_source'
)
AND NOT EXISTS (
    SELECT 1
    FROM sys.indexes
    WHERE object_id = OBJECT_ID('dbo.tblLeaveBalanceAccrualHistories')
      AND name = 'UX_tblLeaveBalanceAccrualHistories_balance_date_source'
)
    EXEC sp_rename
        'dbo.tblLeaveBalanceAccrualHistories.UX_tblLeaveBalanceCreditHistories_balance_date_source',
        'UX_tblLeaveBalanceAccrualHistories_balance_date_source',
        'INDEX';

IF EXISTS (
    SELECT 1
    FROM sys.indexes
    WHERE object_id = OBJECT_ID('dbo.tblLeaveBalanceAccrualHistories')
      AND name = 'tblleavebalancecredithistories_leave_balance_id_accrual_date_unique'
)
AND NOT EXISTS (
    SELECT 1
    FROM sys.indexes
    WHERE object_id = OBJECT_ID('dbo.tblLeaveBalanceAccrualHistories')
      AND name = 'tblleavebalanceaccrualhistories_leave_balance_id_accrual_date_unique'
)
    EXEC sp_rename
        'dbo.tblLeaveBalanceAccrualHistories.tblleavebalancecredithistories_leave_balance_id_accrual_date_unique',
        'tblleavebalanceaccrualhistories_leave_balance_id_accrual_date_unique',
        'INDEX';

IF EXISTS (
    SELECT 1
    FROM sys.foreign_keys
    WHERE parent_object_id = OBJECT_ID('dbo.tblLeaveBalanceAccrualHistories')
      AND name = 'tblleavebalancecredithistories_leave_balance_id_foreign'
)
AND NOT EXISTS (
    SELECT 1
    FROM sys.foreign_keys
    WHERE parent_object_id = OBJECT_ID('dbo.tblLeaveBalanceAccrualHistories')
      AND name = 'tblleavebalanceaccrualhistories_leave_balance_id_foreign'
)
    EXEC sp_rename
        'dbo.tblleavebalancecredithistories_leave_balance_id_foreign',
        'tblleavebalanceaccrualhistories_leave_balance_id_foreign',
        'OBJECT';
SQL);
    }
};

