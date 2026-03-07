<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // 1. departments
        Schema::create('tblDepartments', function (Blueprint $table) {
            $table->id();
            $table->string('name')->unique();
            $table->timestamps();
        });

        // 2. hr_accounts
        Schema::create('tblHRAccounts', function (Blueprint $table) {
            $table->id();
            $table->string('full_name');
            $table->string('username')->unique();
            $table->string('password');
            $table->timestamps();
        });

        // 3. department_admins
        Schema::create('tblDepartmentAdmins', function (Blueprint $table) {
            $table->id();
            $table->foreignId('department_id')
                ->unique()
                ->constrained('tblDepartments')
                ->cascadeOnDelete();
            $table->string('full_name');
            $table->string('username')->unique();
            $table->string('password');
            $table->boolean('leave_initialized')->default(false);
            $table->timestamps();
        });

        // 4. employees - HRIS-aligned, control_no as primary key
        Schema::create('tblEmployees', function (Blueprint $table) {
            $table->string('control_no')->primary(); // PK maps to HRIS ControlNo
            $table->string('pmis_no')->nullable(); // maps to HRIS PMISNO
            $table->string('surname');
            $table->string('firstname');
            $table->string('middlename')->nullable();
            $table->string('sex')->nullable();
            $table->date('birth_date')->nullable();
            $table->string('office')->nullable();
            $table->string('status')->default('');
            $table->string('designation')->nullable();
            $table->decimal('rate_day', 10, 2)->nullable();
            $table->decimal('rate_mon', 10, 2)->nullable();
            $table->date('from_date')->nullable();
            $table->date('to_date')->nullable();
            $table->string('address')->nullable();
            $table->string('tel_no')->nullable();
            $table->timestamps();

            $table->index('office');
            $table->index('status');
        });

        // SQL Server filtered unique index: allow multiple NULLs for pmis_no
        DB::statement('CREATE UNIQUE INDEX tblEmployees_pmis_no_unique ON tblEmployees (pmis_no) WHERE pmis_no IS NOT NULL');

        // 5. employee_accounts - FK references employees.control_no
        Schema::create('tblEmployeeAccounts', function (Blueprint $table) {
            $table->id();
            $table->string('employee_id');
            $table->foreign('employee_id')
                ->references('control_no')
                ->on('tblEmployees')
                ->cascadeOnDelete();
            $table->string('username')->unique();
            $table->string('password');
            $table->boolean('must_change_password')->default(true);
            $table->timestamps();
        });

        // 6. leave_types
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
        Schema::dropIfExists('tblEmployeeAccounts');
        DB::statement('DROP INDEX IF EXISTS tblEmployees_pmis_no_unique ON tblEmployees');
        Schema::dropIfExists('tblEmployees');
        Schema::dropIfExists('tblDepartmentAdmins');
        Schema::dropIfExists('tblHRAccounts');
        Schema::dropIfExists('tblDepartments');
    }
};
