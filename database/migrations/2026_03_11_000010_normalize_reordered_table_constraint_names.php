<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration {
    public function up(): void
    {
        if (DB::connection()->getDriverName() !== 'sqlsrv') {
            return;
        }

        $this->renameConstraint('PK_tblDepartmentHeads_reordered_tmp', 'PK_tblDepartmentHeads');
        $this->renameConstraint('PK_tblLeaveApplications_reordered_tmp', 'PK_tblLeaveApplications');
    }

    public function down(): void
    {
        if (DB::connection()->getDriverName() !== 'sqlsrv') {
            return;
        }

        $this->renameConstraint('PK_tblDepartmentHeads', 'PK_tblDepartmentHeads_reordered_tmp');
        $this->renameConstraint('PK_tblLeaveApplications', 'PK_tblLeaveApplications_reordered_tmp');
    }

    private function renameConstraint(string $from, string $to): void
    {
        $exists = DB::selectOne(
            'SELECT 1 AS found WHERE OBJECT_ID(?, ?) IS NOT NULL AND OBJECT_ID(?, ?) IS NULL',
            ["dbo.{$from}", 'PK', "dbo.{$to}", 'PK']
        );

        if (!$exists) {
            return;
        }

        DB::statement("EXEC sp_rename N'dbo.{$from}', N'{$to}', N'OBJECT'");
    }
};
