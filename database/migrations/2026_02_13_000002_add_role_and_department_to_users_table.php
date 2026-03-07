<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * LOCAL DEVELOPMENT ONLY — runs against LMS_DB.
 * Adds department assignment and role (ADMIN/HEAD) to users.
 * Enforces one ADMIN and one HEAD per department via filtered unique index
 * (allows multiple NULLs so existing users are not treated as duplicates).
 */
return new class extends Migration
{
    private const INDEX_NAME = 'users_department_id_role_unique';

    /**
     * Run the migrations.
     */
    public function up(): void
    {
        $hasDepartmentId = Schema::hasColumn('users', 'department_id');
        $hasRole = Schema::hasColumn('users', 'role');

        if (! $hasDepartmentId || ! $hasRole) {
            Schema::table('users', function (Blueprint $table) use ($hasDepartmentId, $hasRole) {
                if (! $hasDepartmentId) {
                    $table->foreignId('department_id')
                        ->nullable()
                        ->after('id')
                        ->constrained('departments')
                        ->nullOnDelete();
                }
                if (! $hasRole) {
                    $table->enum('role', ['ADMIN', 'HEAD'])
                        ->nullable()
                        ->after('department_id');
                }
            });
        }

        // Filtered unique index: only one (department_id, role) when both are set.
        // Multiple rows with (NULL, NULL) are allowed (existing users).
        $driver = Schema::getConnection()->getDriverName();
        if ($driver === 'sqlsrv') {
            $indexExists = DB::selectOne(
                "SELECT 1 FROM sys.indexes WHERE name = ? AND object_id = OBJECT_ID('users')",
                [self::INDEX_NAME]
            );
            if (! $indexExists) {
                DB::statement(sprintf(
                    'CREATE UNIQUE INDEX %s ON users ([department_id], [role]) WHERE [department_id] IS NOT NULL AND [role] IS NOT NULL',
                    self::INDEX_NAME
                ));
            }
        } else {
            Schema::table('users', function (Blueprint $table) {
                $table->unique(['department_id', 'role'], self::INDEX_NAME);
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        $driver = Schema::getConnection()->getDriverName();
        if ($driver === 'sqlsrv') {
            $indexExists = DB::selectOne(
                "SELECT 1 FROM sys.indexes WHERE name = ? AND object_id = OBJECT_ID('users')",
                [self::INDEX_NAME]
            );
            if ($indexExists) {
                DB::statement(sprintf('DROP INDEX %s ON users', self::INDEX_NAME));
            }
        } else {
            Schema::table('users', function (Blueprint $table) {
                $table->dropUnique(self::INDEX_NAME);
            });
        }

        if (Schema::hasColumn('users', 'department_id') || Schema::hasColumn('users', 'role')) {
            Schema::table('users', function (Blueprint $table) {
                if (Schema::hasColumn('users', 'department_id')) {
                    $table->dropConstrainedForeignId('department_id');
                }
                if (Schema::hasColumn('users', 'role')) {
                    $table->dropColumn('role');
                }
            });
        }
    }
};