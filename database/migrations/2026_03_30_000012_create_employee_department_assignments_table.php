<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('tblEmployeeDepartmentAssignments', function (Blueprint $table) {
            $table->id();
            $table->string('employee_control_no')->unique();
            $table->foreignId('department_id')
                ->constrained('tblDepartments')
                ->cascadeOnDelete();
            $table->unsignedBigInteger('assigned_by_department_admin_id')->nullable();
            $table->timestamp('assigned_at')->nullable();
            $table->timestamps();

            $table->index('department_id', 'IX_tblEmployeeDepartmentAssignments_department_id');
            $table->index('assigned_by_department_admin_id', 'IX_tblEmployeeDepartmentAssignments_assigned_by');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tblEmployeeDepartmentAssignments');
    }
};
