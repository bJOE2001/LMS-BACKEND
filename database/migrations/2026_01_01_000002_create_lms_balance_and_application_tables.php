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
        // Leave balances
        Schema::create('tblLeaveBalances', function (Blueprint $table) {
            $table->id();
            $table->string('employee_control_no');
            $table->foreign('employee_control_no')
                ->references('control_no')
                ->on('tblEmployees')
                ->cascadeOnDelete();
            $table->string('employee_name')->nullable();
            $table->foreignId('leave_type_id')
                ->constrained('tblLeaveTypes')
                ->cascadeOnDelete();
            $table->string('leave_type_name')->nullable();
            $table->decimal('balance', 8, 2)->default(0);
            $table->date('last_accrual_date')->nullable();
            $table->unsignedSmallInteger('year')->nullable();
            $table->timestamps();

            $table->unique(
                ['employee_control_no', 'leave_type_id'],
                'UX_tblLeaveBalances_employee_control_no_leave_type_id'
            );
        });

        // Leave balance credit histories
        Schema::create('tblLeaveBalanceCreditHistories', function (Blueprint $table) {
            $table->id();
            $table->foreignId('leave_balance_id')
                ->constrained('tblLeaveBalances')
                ->cascadeOnDelete();
            $table->string('employee_control_no')->nullable();
            $table->string('employee_name')->nullable();
            $table->string('leave_type_name')->nullable();
            $table->decimal('credits_added', 8, 2);
            $table->date('accrual_date');
            $table->string('source', 32)->default('AUTOMATED');
            $table->timestamps();

            $table->unique(
                ['leave_balance_id', 'accrual_date', 'source'],
                'UX_tblLeaveBalanceCreditHistories_balance_date_source'
            );
        });

        // Leave applications
        Schema::create('tblLeaveApplications', function (Blueprint $table) {
            $table->id();
            $table->foreignId('applicant_admin_id')
                ->nullable()
                ->constrained('tblDepartmentAdmins')
                ->noActionOnDelete();
            $table->string('employee_control_no', 64)->nullable();
            $table->string('employee_name')->nullable();
            $table->foreignId('leave_type_id')
                ->constrained('tblLeaveTypes')
                ->cascadeOnDelete();

            $table->date('start_date')->nullable();
            $table->date('end_date')->nullable();
            $table->longText('selected_dates')->nullable();
            $table->longText('selected_date_pay_status')->nullable();
            $table->longText('selected_date_coverage')->nullable();

            $table->decimal('total_days', 5, 2);
            $table->decimal('deductible_days', 5, 2)->nullable();
            $table->decimal('cto_deducted_hours', 8, 2)->nullable();
            $table->string('pay_mode', 8)->default('WP');
            $table->decimal('linked_forced_leave_deducted_days', 5, 2)->nullable();
            $table->decimal('linked_vacation_leave_deducted_days', 5, 2)->nullable();

            $table->text('reason')->nullable();
            $table->string('commutation', 32)->nullable();
            $table->boolean('is_monetization')->default(false);
            $table->decimal('equivalent_amount', 12, 2)->nullable();

            $table->string('status')->default('PENDING_ADMIN');
            $table->foreignId('admin_id')
                ->nullable()
                ->constrained('tblDepartmentAdmins')
                ->nullOnDelete();
            $table->timestamp('admin_approved_at')->nullable();
            $table->foreignId('hr_id')
                ->nullable()
                ->constrained('tblHRAccounts')
                ->nullOnDelete();
            $table->timestamp('hr_approved_at')->nullable();
            $table->date('recall_effective_date')->nullable();
            $table->longText('recall_selected_dates')->nullable();
            $table->text('remarks')->nullable();

            $table->boolean('requires_documents')->default(false);
            $table->boolean('attachment_required')->default(false);
            $table->boolean('attachment_submitted')->default(false);
            $table->string('attachment_reference', 500)->nullable();

            $table->timestamps();

            $table->index(['applicant_admin_id', 'status']);
            $table->index('status');
            $table->index(['employee_control_no', 'status'], 'IX_tblLeaveApplications_employee_control_no_status');
            $table->index(['status', 'created_at'], 'IX_tblLeaveApplications_status_created_at');
            $table->index(['employee_control_no', 'created_at'], 'IX_tblLeaveApplications_employee_control_no_created_at');
            $table->index(['leave_type_id', 'status'], 'IX_tblLeaveApplications_leave_type_status');
            $table->index(['status', 'hr_approved_at'], 'IX_tblLeaveApplications_status_hr_approved_at');
        });

        // Leave application logs
        Schema::create('tblLeaveApplicationLogs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('leave_application_id')
                ->constrained('tblLeaveApplications')
                ->cascadeOnDelete();
            $table->string('action', 32);
            $table->string('performed_by_type', 16);
            $table->unsignedBigInteger('performed_by_id');
            $table->text('remarks')->nullable();
            $table->timestamp('created_at')->useCurrent();
        });

        // Notifications
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

        // Leave application update requests
        Schema::create('tblLeaveApplicationUpdateRequests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('leave_application_id')
                ->constrained('tblLeaveApplications')
                ->cascadeOnDelete();
            $table->string('employee_control_no', 64)->nullable();
            $table->string('employee_name')->nullable();
            $table->longText('requested_payload');
            $table->text('requested_reason')->nullable();
            $table->string('previous_status', 32)->nullable();
            $table->string('requested_by_control_no', 64)->nullable();
            $table->timestamp('requested_at')->nullable();
            $table->string('status', 32)->default('PENDING');
            $table->unsignedBigInteger('reviewed_by_hr_id')->nullable();
            $table->timestamp('reviewed_at')->nullable();
            $table->text('review_remarks')->nullable();
            $table->timestamps();

            $table->index(
                ['leave_application_id', 'status'],
                'IX_tblLeaveAppUpdateReq_leave_application_status'
            );
            $table->index('employee_control_no', 'IX_tblLeaveAppUpdateReq_employee_control_no');
            $table->index('requested_by_control_no', 'IX_tblLeaveAppUpdateReq_requested_by');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('tblLeaveApplicationUpdateRequests');
        Schema::dropIfExists('tblNotifications');
        Schema::dropIfExists('tblLeaveApplicationLogs');
        Schema::dropIfExists('tblLeaveApplications');
        Schema::dropIfExists('tblLeaveBalanceCreditHistories');
        Schema::dropIfExists('tblLeaveBalances');
    }
};
