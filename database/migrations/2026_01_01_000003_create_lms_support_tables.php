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
        Schema::create('tblWorkScheduleSettings', function (Blueprint $table): void {
            $table->id();
            $table->string('setting_key')->unique();
            $table->time('work_start_time');
            $table->time('work_end_time');
            $table->time('break_start_time')->nullable();
            $table->time('break_end_time')->nullable();
            $table->decimal('working_hours_per_day', 5, 2)->default(8.00);
            $table->decimal('whole_day_leave_deduction', 6, 3)->default(1.000);
            $table->decimal('half_day_leave_deduction', 6, 3)->default(0.500);
            $table->text('notes')->nullable();
            $table->foreignId('updated_by_hr_account_id')
                ->nullable()
                ->constrained('tblHRAccounts')
                ->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('tblEmployeeWorkScheduleOverrides', function (Blueprint $table): void {
            $table->id();
            $table->string('employee_control_no')->unique();
            $table->string('employee_name')->nullable();
            $table->string('office')->nullable();
            $table->string('designation')->nullable();
            $table->string('status')->nullable();
            $table->time('work_start_time');
            $table->time('work_end_time');
            $table->time('break_start_time')->nullable();
            $table->time('break_end_time')->nullable();
            $table->decimal('working_hours_per_day', 5, 2)->default(8.00);
            $table->decimal('whole_day_leave_deduction', 6, 3)->default(1.000);
            $table->decimal('half_day_leave_deduction', 6, 3)->default(0.500);
            $table->text('notes')->nullable();
            $table->boolean('is_active')->default(true);
            $table->foreignId('updated_by_hr_account_id')
                ->nullable()
                ->constrained('tblHRAccounts')
                ->nullOnDelete();
            $table->timestamps();

            $table->index(['is_active', 'employee_control_no'], 'IX_tblEmployeeWorkScheduleOverrides_active_employee');
        });

        // Department heads
        Schema::create('tblDepartmentHeads', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('department_id')
                ->unique()
                ->constrained('tblDepartments')
                ->cascadeOnDelete();
            $table->string('control_no')->nullable();
            $table->string('surname')->nullable();
            $table->string('firstname')->nullable();
            $table->string('middlename')->nullable();
            $table->string('office')->nullable();
            $table->string('status')->nullable();
            $table->string('designation')->nullable();
            $table->decimal('rate_mon', 10, 2)->nullable();
            $table->string('full_name');
            $table->string('position')->nullable();
            $table->timestamps();

            $table->index('control_no');
        });

        // COC applications
        Schema::create('tblCOCApplications', function (Blueprint $table): void {
            $table->id();
            $table->string('employee_control_no');
            $table->string('employee_name')->nullable();
            $table->foreign('employee_control_no')
                ->references('control_no')
                ->on('tblEmployees')
                ->noActionOnDelete();
            $table->string('status', 16)->default('PENDING');
            $table->foreignId('reviewed_by_admin_id')
                ->nullable()
                ->constrained('tblDepartmentAdmins')
                ->noActionOnDelete();
            $table->timestamp('admin_reviewed_at')->nullable();
            $table->foreignId('reviewed_by_hr_id')
                ->nullable()
                ->constrained('tblHRAccounts')
                ->noActionOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->foreignId('cto_leave_type_id')
                ->nullable()
                ->constrained('tblLeaveTypes')
                ->noActionOnDelete();
            $table->decimal('cto_credited_days', 8, 2)->nullable();
            $table->timestamp('cto_credited_at')->nullable();
            $table->unsignedInteger('total_minutes');
            $table->unsignedSmallInteger('application_year')->nullable();
            $table->unsignedTinyInteger('application_month')->nullable();
            $table->decimal('credited_hours', 8, 2)->nullable();
            $table->text('remarks')->nullable();
            $table->timestamp('submitted_at')->useCurrent();
            $table->timestamps();

            $table->index(['employee_control_no', 'status'], 'IX_tblCOCApplications_employee_control_no_status');
            $table->index(['employee_control_no', 'created_at'], 'IX_tblCOCApplications_employee_control_no_created_at');
            $table->index(['status', 'created_at'], 'ix_tblcocapplications_status_created_at');
            $table->index(['reviewed_by_admin_id', 'admin_reviewed_at'], 'ix_tblcocapplications_admin_review');
            $table->index(['employee_control_no', 'application_year', 'application_month'], 'ix_tblcocapplications_employee_period');
        });

        Schema::create('tblCOCApplicationRows', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('coc_application_id')
                ->constrained('tblCOCApplications')
                ->cascadeOnDelete();
            $table->string('employee_control_no');
            $table->string('employee_name')->nullable();
            $table->unsignedInteger('line_no');
            $table->date('overtime_date');
            $table->text('nature_of_overtime');
            $table->string('time_from', 5);
            $table->string('time_to', 5);
            $table->unsignedInteger('minutes');
            $table->unsignedInteger('cumulative_minutes');
            $table->string('credit_category', 16)->nullable();
            $table->decimal('credit_multiplier', 4, 2)->nullable();
            $table->unsignedInteger('creditable_minutes')->nullable();
            $table->decimal('credited_hours', 8, 2)->nullable();
            $table->timestamps();

            $table->index(['coc_application_id', 'line_no']);
            $table->index(['employee_control_no', 'overtime_date'], 'IX_tblCOCApplicationRows_employee_control_no_overtime_date');
        });

        if (Schema::hasTable('tblNotifications') && !Schema::hasColumn('tblNotifications', 'coc_application_id')) {
            Schema::table('tblNotifications', function (Blueprint $table): void {
                $table->foreignId('coc_application_id')
                    ->nullable()
                    ->after('leave_application_id')
                    ->constrained('tblCOCApplications')
                    ->nullOnDelete();
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (Schema::hasTable('tblNotifications') && Schema::hasColumn('tblNotifications', 'coc_application_id')) {
            Schema::table('tblNotifications', function (Blueprint $table): void {
                $table->dropConstrainedForeignId('coc_application_id');
            });
        }

        Schema::dropIfExists('tblEmployeeWorkScheduleOverrides');
        Schema::dropIfExists('tblWorkScheduleSettings');
        Schema::dropIfExists('tblCOCApplicationRows');
        Schema::dropIfExists('tblCOCApplications');
        Schema::dropIfExists('tblDepartmentHeads');
    }
};
