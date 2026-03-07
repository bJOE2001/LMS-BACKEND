<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        // 1. Remove employee_id from department_admins if it exists (cleanup previous attempt)
        if (Schema::hasColumn('department_admins', 'employee_id')) {
            Schema::table('department_admins', function (Blueprint $table) {
                $table->dropForeign(['employee_id']);
                $table->dropColumn('employee_id');
            });
        }

        // 2. Add leave_initialized to department_admins
        Schema::table('department_admins', function (Blueprint $table) {
            $table->boolean('leave_initialized')->default(false)->after('password');
        });

        // 3. Create admin_leave_balances table
        Schema::create('admin_leave_balances', function (Blueprint $table) {
            $table->id();
            $table->foreignId('admin_id')->constrained('department_admins')->cascadeOnDelete();
            $table->foreignId('leave_type_id')->constrained('leave_types')->cascadeOnDelete();
            $table->decimal('balance', 8, 2)->default(0);
            $table->integer('year');
            $table->timestamp('initialized_at')->nullable();
            $table->timestamps();

            // Ensure one balance record per admin per leave type per year
            $table->unique(['admin_id', 'leave_type_id', 'year']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('admin_leave_balances');
        Schema::table('department_admins', function (Blueprint $table) {
            $table->dropColumn('leave_initialized');
        });
    }
};
