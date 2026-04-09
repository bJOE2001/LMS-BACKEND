<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        if (!Schema::hasColumn('tblHRAccounts', 'active_personal_access_token_id')) {
            Schema::table('tblHRAccounts', function (Blueprint $table): void {
                $table->unsignedBigInteger('active_personal_access_token_id')
                    ->nullable()
                    ->after('must_change_password');
                $table->index(
                    'active_personal_access_token_id',
                    'IX_tblHRAccounts_active_personal_access_token_id'
                );
            });
        }

        if (!Schema::hasColumn('tblDepartmentAdmins', 'active_personal_access_token_id')) {
            Schema::table('tblDepartmentAdmins', function (Blueprint $table): void {
                $table->unsignedBigInteger('active_personal_access_token_id')
                    ->nullable()
                    ->after('must_change_password');
                $table->index(
                    'active_personal_access_token_id',
                    'IX_tblDepartmentAdmins_active_personal_access_token_id'
                );
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('tblDepartmentAdmins', 'active_personal_access_token_id')) {
            Schema::table('tblDepartmentAdmins', function (Blueprint $table): void {
                $table->dropIndex('IX_tblDepartmentAdmins_active_personal_access_token_id');
                $table->dropColumn('active_personal_access_token_id');
            });
        }

        if (Schema::hasColumn('tblHRAccounts', 'active_personal_access_token_id')) {
            Schema::table('tblHRAccounts', function (Blueprint $table): void {
                $table->dropIndex('IX_tblHRAccounts_active_personal_access_token_id');
                $table->dropColumn('active_personal_access_token_id');
            });
        }
    }
};
