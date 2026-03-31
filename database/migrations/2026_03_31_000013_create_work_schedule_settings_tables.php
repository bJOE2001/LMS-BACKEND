<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        if (!Schema::hasTable('tblWorkScheduleSettings')) {
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
        }

        if (!Schema::hasTable('tblEmployeeWorkScheduleOverrides')) {
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
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('tblEmployeeWorkScheduleOverrides');
        Schema::dropIfExists('tblWorkScheduleSettings');
    }
};
