<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        if (!Schema::hasColumn('tblDepartmentAdmins', 'is_default_account')) {
            Schema::table('tblDepartmentAdmins', function (Blueprint $table): void {
                $table->boolean('is_default_account')->default(false);
            });
        }

        // Drop unique constraint on department_id to allow up to two accounts per department.
        try {
            Schema::table('tblDepartmentAdmins', function (Blueprint $table): void {
                $table->dropUnique(['department_id']);
            });
        } catch (\Throwable) {
            // SQL Server fallback for differing auto-generated index names.
            DB::statement("
                IF EXISTS (
                    SELECT 1
                    FROM sys.indexes
                    WHERE name = 'tbldepartmentadmins_department_id_unique'
                      AND object_id = OBJECT_ID('tblDepartmentAdmins')
                )
                DROP INDEX [tbldepartmentadmins_department_id_unique] ON [tblDepartmentAdmins]
            ");
            DB::statement("
                IF EXISTS (
                    SELECT 1
                    FROM sys.indexes
                    WHERE name = 'tblDepartmentAdmins_department_id_unique'
                      AND object_id = OBJECT_ID('tblDepartmentAdmins')
                )
                DROP INDEX [tblDepartmentAdmins_department_id_unique] ON [tblDepartmentAdmins]
            ");
        }

        try {
            Schema::table('tblDepartmentAdmins', function (Blueprint $table): void {
                $table->index('department_id', 'IX_tblDepartmentAdmins_department_id');
            });
        } catch (\Throwable) {
            // Ignore if index already exists.
        }

        try {
            Schema::table('tblDepartmentAdmins', function (Blueprint $table): void {
                $table->index(['department_id', 'is_default_account'], 'IX_tblDepartmentAdmins_department_default');
            });
        } catch (\Throwable) {
            // Ignore if index already exists.
        }

        // Backfill likely seeded default accounts (empty employee_control_no).
        DB::table('tblDepartmentAdmins')
            ->where(function ($query): void {
                $query->whereNull('employee_control_no')
                    ->orWhereRaw("LTRIM(RTRIM(CONVERT(VARCHAR(64), employee_control_no))) = ''");
            })
            ->update(['is_default_account' => true]);

        // Ensure only one default account marker per department.
        $departmentIds = DB::table('tblDepartmentAdmins')
            ->select('department_id')
            ->where('is_default_account', true)
            ->groupBy('department_id')
            ->havingRaw('COUNT(*) > 1')
            ->pluck('department_id');

        foreach ($departmentIds as $departmentId) {
            $keepId = DB::table('tblDepartmentAdmins')
                ->where('department_id', $departmentId)
                ->where('is_default_account', true)
                ->orderBy('id')
                ->value('id');

            DB::table('tblDepartmentAdmins')
                ->where('department_id', $departmentId)
                ->where('is_default_account', true)
                ->where('id', '!=', $keepId)
                ->update(['is_default_account' => false]);
        }
    }

    public function down(): void
    {
        $hasDuplicateDepartments = DB::table('tblDepartmentAdmins')
            ->select('department_id')
            ->groupBy('department_id')
            ->havingRaw('COUNT(*) > 1')
            ->exists();

        if ($hasDuplicateDepartments) {
            throw new RuntimeException(
                'Cannot rollback: tblDepartmentAdmins has multiple rows per department.'
            );
        }

        try {
            Schema::table('tblDepartmentAdmins', function (Blueprint $table): void {
                $table->dropIndex('IX_tblDepartmentAdmins_department_default');
            });
        } catch (\Throwable) {
            // Ignore when index does not exist.
        }

        try {
            Schema::table('tblDepartmentAdmins', function (Blueprint $table): void {
                $table->dropIndex('IX_tblDepartmentAdmins_department_id');
            });
        } catch (\Throwable) {
            // Ignore when index does not exist.
        }

        Schema::table('tblDepartmentAdmins', function (Blueprint $table): void {
            $table->unique('department_id');
        });

        if (Schema::hasColumn('tblDepartmentAdmins', 'is_default_account')) {
            Schema::table('tblDepartmentAdmins', function (Blueprint $table): void {
                $table->dropColumn('is_default_account');
            });
        }
    }
};
