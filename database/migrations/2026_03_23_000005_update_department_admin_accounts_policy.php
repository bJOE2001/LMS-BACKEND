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

        // Drop unique constraint on department_id to allow multiple accounts per department.
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

        // Intentionally no data backfill here.
        // Keep migrations schema-only to avoid mutating live records.
    }

    public function down(): void
    {
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
