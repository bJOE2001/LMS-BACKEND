<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Add username to users for login. Backfill existing rows so login works.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('users', 'username')) {
            Schema::table('users', function (Blueprint $table) {
                $table->string('username')->nullable()->after('name');
            });
        }

        // Backfill so existing users can log in with username (no duplicate NULLs for unique)
        $driver = Schema::getConnection()->getDriverName();
        if ($driver === 'sqlsrv') {
            DB::statement("UPDATE users SET username = CONCAT('user_', id) WHERE username IS NULL");
        } else {
            DB::table('users')->whereNull('username')->orderBy('id')->each(function ($user) {
                DB::table('users')->where('id', $user->id)->update([
                    'username' => 'user_' . $user->id,
                ]);
            });
        }

        if ($driver === 'sqlsrv') {
            $indexExists = DB::selectOne(
                "SELECT 1 FROM sys.indexes WHERE name = 'users_username_unique' AND object_id = OBJECT_ID('users')"
            );
            if (! $indexExists) {
                DB::statement('ALTER TABLE users ALTER COLUMN username NVARCHAR(255) NOT NULL');
                DB::statement('CREATE UNIQUE INDEX users_username_unique ON users (username)');
            }
        } else {
            Schema::table('users', function (Blueprint $table) {
                $table->string('username')->nullable(false)->unique()->change();
            });
        }
    }

    public function down(): void
    {
        $driver = Schema::getConnection()->getDriverName();
        if ($driver === 'sqlsrv') {
            DB::statement('DROP INDEX users_username_unique ON users');
        } else {
            Schema::table('users', function (Blueprint $table) {
                $table->dropUnique(['username']);
            });
        }
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('username');
        });
    }
};
