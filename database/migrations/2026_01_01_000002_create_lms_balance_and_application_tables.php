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
        // 1. leave_balances — employee_id references employees.control_no (string)
        Schema::create('tblLeaveBalances', function (Blueprint $table) {
            $table->id();
            $table->string('employee_id');
            $table->foreign('employee_id')
                ->references('control_no')
                ->on('tblEmployees')
                ->cascadeOnDelete();
            $table->foreignId('leave_type_id')
                ->constrained('tblLeaveTypes')
                ->cascadeOnDelete();
            $table->decimal('balance', 8, 2)->default(0);
            $table->timestamp('initialized_at')->nullable();
            $table->date('last_accrual_date')->nullable();
            $table->unsignedSmallInteger('year')->nullable();
            $table->timestamps();

            $table->unique(['employee_id', 'leave_type_id']);
        });

        // 2. admin_leave_balances
        Schema::create('tblAdminLeaveBalances', function (Blueprint $table) {
            $table->id();
            $table->foreignId('admin_id')->constrained('tblDepartmentAdmins')->cascadeOnDelete();
            $table->foreignId('leave_type_id')->constrained('tblLeaveTypes')->cascadeOnDelete();
            $table->decimal('balance', 8, 2)->default(0);
            $table->integer('year');
            $table->timestamp('initialized_at')->nullable();
            $table->timestamps();

            $table->unique(['admin_id', 'leave_type_id', 'year']);
        });

        // 3. leave_applications — employee_id references employees.control_no (string, nullable)
        Schema::create('tblLeaveApplications', function (Blueprint $table) {
            $table->id();
            $table->foreignId('applicant_admin_id')
                ->nullable()
                ->constrained('tblDepartmentAdmins')
                ->noActionOnDelete();
            $table->string('employee_id')->nullable();
            $table->foreign('employee_id')
                ->references('control_no')
                ->on('tblEmployees')
                ->noActionOnDelete();
            $table->foreignId('leave_type_id')
                ->constrained('tblLeaveTypes')
                ->cascadeOnDelete();
            $table->date('start_date');
            $table->date('end_date');
            $table->decimal('total_days', 5, 2);
            $table->text('reason')->nullable();
            $table->enum('status', [
                'PENDING_ADMIN',
                'PENDING_HR',
                'APPROVED',
                'REJECTED',
            ])->default('PENDING_ADMIN');
            $table->unsignedBigInteger('admin_id')->nullable();
            $table->unsignedBigInteger('hr_id')->nullable();
            $table->timestamp('admin_approved_at')->nullable();
            $table->timestamp('hr_approved_at')->nullable();
            $table->text('remarks')->nullable();
            $table->timestamps();

            $table->index(['employee_id', 'status']);
            $table->index(['applicant_admin_id', 'status']);
            $table->index('status');
        });

        // 4. leave_application_logs
        Schema::create('tblLeaveApplicationLogs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('leave_application_id')
                ->constrained('tblLeaveApplications')
                ->cascadeOnDelete();
            $table->enum('action', [
                'SUBMITTED',
                'ADMIN_APPROVED',
                'ADMIN_REJECTED',
                'HR_APPROVED',
                'HR_REJECTED',
            ]);
            $table->enum('performed_by_type', ['EMPLOYEE', 'ADMIN', 'HR']);
            $table->unsignedBigInteger('performed_by_id');
            $table->text('remarks')->nullable();
            $table->timestamp('created_at')->useCurrent();
        });

        // 5. notifications
        Schema::create('tblNotifications', function (Blueprint $table) {
            $table->id();
            $table->morphs('notifiable');
            $table->string('type');
            $table->string('title');
            $table->text('message');
            $table->foreignId('leave_application_id')
                ->nullable()
                ->constrained('tblLeaveApplications')
                ->nullOnDelete();
            $table->timestamp('read_at')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('tblNotifications');
        Schema::dropIfExists('tblLeaveApplicationLogs');
        Schema::dropIfExists('tblLeaveApplications');
        Schema::dropIfExists('tblAdminLeaveBalances');
        Schema::dropIfExists('tblLeaveBalances');
    }
};
