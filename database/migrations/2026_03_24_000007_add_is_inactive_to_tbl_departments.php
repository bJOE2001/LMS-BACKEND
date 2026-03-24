<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasColumn('tblDepartments', 'is_inactive')) {
            Schema::table('tblDepartments', function (Blueprint $table): void {
                $table->boolean('is_inactive')->default(false);
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('tblDepartments', 'is_inactive')) {
            Schema::table('tblDepartments', function (Blueprint $table): void {
                $table->dropColumn('is_inactive');
            });
        }
    }
};
