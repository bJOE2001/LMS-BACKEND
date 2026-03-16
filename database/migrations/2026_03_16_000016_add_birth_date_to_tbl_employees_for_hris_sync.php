<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        if (!Schema::hasTable('tblEmployees') || Schema::hasColumn('tblEmployees', 'birth_date')) {
            return;
        }

        Schema::table('tblEmployees', function (Blueprint $table): void {
            $table->date('birth_date')->nullable();
        });
    }

    public function down(): void
    {
        if (!Schema::hasTable('tblEmployees') || !Schema::hasColumn('tblEmployees', 'birth_date')) {
            return;
        }

        Schema::table('tblEmployees', function (Blueprint $table): void {
            $table->dropColumn('birth_date');
        });
    }
};
