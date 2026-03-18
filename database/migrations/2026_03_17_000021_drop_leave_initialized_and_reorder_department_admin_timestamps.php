<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        if (!Schema::hasTable('tblDepartmentAdmins')) {
            return;
        }

        if (Schema::hasColumn('tblDepartmentAdmins', 'leave_initialized')) {
            Schema::table('tblDepartmentAdmins', function (Blueprint $table): void {
                $table->dropColumn('leave_initialized');
            });
        }

        if (DB::connection()->getDriverName() !== 'sqlsrv') {
            return;
        }

        if (!Schema::hasColumn('tblDepartmentAdmins', 'created_at') || !Schema::hasColumn('tblDepartmentAdmins', 'updated_at')) {
            return;
        }

        $order = $this->columnOrder('tblDepartmentAdmins');
        $count = count($order);
        if (
            $count >= 2
            && $order[$count - 2] === 'created_at'
            && $order[$count - 1] === 'updated_at'
        ) {
            return;
        }

        DB::beginTransaction();

        try {
            if (Schema::hasColumn('tblDepartmentAdmins', 'created_at_reorder_tmp')) {
                DB::statement('ALTER TABLE dbo.tblDepartmentAdmins DROP COLUMN created_at_reorder_tmp');
            }

            if (Schema::hasColumn('tblDepartmentAdmins', 'updated_at_reorder_tmp')) {
                DB::statement('ALTER TABLE dbo.tblDepartmentAdmins DROP COLUMN updated_at_reorder_tmp');
            }

            DB::statement('ALTER TABLE dbo.tblDepartmentAdmins ADD created_at_reorder_tmp DATETIME2 NULL, updated_at_reorder_tmp DATETIME2 NULL');
            DB::statement('UPDATE dbo.tblDepartmentAdmins SET created_at_reorder_tmp = created_at, updated_at_reorder_tmp = updated_at');
            DB::statement('ALTER TABLE dbo.tblDepartmentAdmins DROP COLUMN created_at, updated_at');
            DB::statement("EXEC sp_rename 'dbo.tblDepartmentAdmins.created_at_reorder_tmp', 'created_at', 'COLUMN'");
            DB::statement("EXEC sp_rename 'dbo.tblDepartmentAdmins.updated_at_reorder_tmp', 'updated_at', 'COLUMN'");

            DB::commit();
        } catch (\Throwable $e) {
            DB::rollBack();
            throw $e;
        }
    }

    public function down(): void
    {
        if (!Schema::hasTable('tblDepartmentAdmins')) {
            return;
        }

        if (!Schema::hasColumn('tblDepartmentAdmins', 'leave_initialized')) {
            Schema::table('tblDepartmentAdmins', function (Blueprint $table): void {
                $table->boolean('leave_initialized')->default(false);
            });
        }
    }

    private function columnOrder(string $table): array
    {
        return DB::table('INFORMATION_SCHEMA.COLUMNS')
            ->where('TABLE_NAME', $table)
            ->orderBy('ORDINAL_POSITION')
            ->pluck('COLUMN_NAME')
            ->map(static fn($column) => (string) $column)
            ->all();
    }
};
