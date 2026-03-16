<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        if (!Schema::hasTable('tblDepartmentAdmins') || Schema::hasColumn('tblDepartmentAdmins', 'employee_control_no')) {
            return;
        }

        Schema::table('tblDepartmentAdmins', function (Blueprint $table): void {
            $table->string('employee_control_no')->nullable()->after('department_id');
            $table->index('employee_control_no');
        });
    }

    public function down(): void
    {
        if (!Schema::hasTable('tblDepartmentAdmins') || !Schema::hasColumn('tblDepartmentAdmins', 'employee_control_no')) {
            return;
        }

        Schema::table('tblDepartmentAdmins', function (Blueprint $table): void {
            $table->dropIndex(['employee_control_no']);
            $table->dropColumn('employee_control_no');
        });
    }
};
