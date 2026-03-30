<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // Departments
        Schema::create('tblDepartments', function (Blueprint $table) {
            $table->id();
            $table->string('name')->unique();
            $table->boolean('is_inactive')->default(false);
            $table->timestamps();
        });

        // HR accounts
        Schema::create('tblHRAccounts', function (Blueprint $table) {
            $table->id();
            $table->string('full_name');
            $table->string('position')->nullable();
            $table->string('username')->unique();
            $table->string('password');
            $table->boolean('must_change_password')->default(false);
            $table->timestamps();
        });

        // Employees
        Schema::create('tblEmployees', function (Blueprint $table) {
            $table->string('control_no')->primary();
            $table->string('surname')->nullable();
            $table->string('firstname')->nullable();
            $table->string('middlename')->nullable();
            $table->string('office')->nullable();
            $table->string('status')->nullable();
            $table->string('designation')->nullable();
            $table->decimal('rate_mon', 10, 2)->nullable();
            $table->date('birth_date')->nullable();
            $table->timestamps();

            $table->index('office');
            $table->index('status');
            $table->index(['office', 'control_no'], 'IX_tblEmployees_office_control_no');
        });

        // Department admins (one per department)
        Schema::create('tblDepartmentAdmins', function (Blueprint $table) {
            $table->id();
            $table->foreignId('department_id')
                ->unique()
                ->constrained('tblDepartments')
                ->cascadeOnDelete();
            $table->string('employee_control_no')->nullable();
            $table->string('full_name');
            $table->string('username')->unique();
            $table->string('password');
            $table->boolean('must_change_password')->default(false);
            $table->timestamps();

            $table->index('employee_control_no');
        });

        // LMS-only employee department reassignments / overlays
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

        // Leave types
        Schema::create('tblLeaveTypes', function (Blueprint $table) {
            $table->id();
            $table->string('name')->unique();
            $table->enum('category', ['ACCRUED', 'RESETTABLE', 'EVENT']);
            $table->decimal('accrual_rate', 5, 2)->nullable();
            $table->unsignedTinyInteger('accrual_day_of_month')->nullable();
            $table->unsignedInteger('max_days')->nullable();
            $table->boolean('is_credit_based')->default(false);
            $table->boolean('resets_yearly')->default(false);
            $table->boolean('requires_documents')->default(false);
            $table->json('allowed_status')->nullable();
            $table->text('description')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('tblLeaveTypes');
        Schema::dropIfExists('tblEmployeeDepartmentAssignments');
        Schema::dropIfExists('tblDepartmentAdmins');
        Schema::dropIfExists('tblEmployees');
        Schema::dropIfExists('tblHRAccounts');
        Schema::dropIfExists('tblDepartments');
    }
};
