<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        try {
            Schema::table('tblDepartments', function (Blueprint $table): void {
                $table->index(['is_inactive', 'name'], 'IX_tblDepartments_active_name');
            });
        } catch (\Throwable) {
            // Ignore when index already exists.
        }

        try {
            Schema::table('tblLeaveBalances', function (Blueprint $table): void {
                $table->index(
                    ['leave_type_id', 'employee_control_no'],
                    'IX_tblLeaveBalances_leave_type_employee'
                );
            });
        } catch (\Throwable) {
            // Ignore when index already exists.
        }

        try {
            Schema::table('tblLeaveApplications', function (Blueprint $table): void {
                $table->index(
                    ['status', 'start_date', 'end_date'],
                    'IX_tblLeaveApplications_status_start_end'
                );
            });
        } catch (\Throwable) {
            // Ignore when index already exists.
        }

        try {
            Schema::table('tblLeaveApplications', function (Blueprint $table): void {
                $table->index(
                    ['employee_control_no', 'leave_type_id', 'status'],
                    'IX_tblLeaveApplications_employee_leave_type_status'
                );
            });
        } catch (\Throwable) {
            // Ignore when index already exists.
        }

        try {
            Schema::table('tblLeaveApplicationLogs', function (Blueprint $table): void {
                $table->index(
                    ['leave_application_id', 'action', 'created_at'],
                    'IX_tblLeaveApplicationLogs_application_action_created'
                );
            });
        } catch (\Throwable) {
            // Ignore when index already exists.
        }

        try {
            Schema::table('tblNotifications', function (Blueprint $table): void {
                $table->index(
                    ['notifiable_type', 'notifiable_id', 'created_at'],
                    'IX_tblNotifications_notifiable_created'
                );
            });
        } catch (\Throwable) {
            // Ignore when index already exists.
        }

        try {
            Schema::table('tblNotifications', function (Blueprint $table): void {
                $table->index(
                    ['notifiable_type', 'notifiable_id', 'read_at'],
                    'IX_tblNotifications_notifiable_read_at'
                );
            });
        } catch (\Throwable) {
            // Ignore when index already exists.
        }

        try {
            Schema::table('tblCOCApplications', function (Blueprint $table): void {
                $table->index(
                    ['employee_control_no', 'status', 'cto_leave_type_id'],
                    'IX_tblCOCApplications_employee_status_cto_type'
                );
            });
        } catch (\Throwable) {
            // Ignore when index already exists.
        }
    }

    public function down(): void
    {
        try {
            Schema::table('tblCOCApplications', function (Blueprint $table): void {
                $table->dropIndex('IX_tblCOCApplications_employee_status_cto_type');
            });
        } catch (\Throwable) {
            // Ignore when index does not exist.
        }

        try {
            Schema::table('tblNotifications', function (Blueprint $table): void {
                $table->dropIndex('IX_tblNotifications_notifiable_read_at');
            });
        } catch (\Throwable) {
            // Ignore when index does not exist.
        }

        try {
            Schema::table('tblNotifications', function (Blueprint $table): void {
                $table->dropIndex('IX_tblNotifications_notifiable_created');
            });
        } catch (\Throwable) {
            // Ignore when index does not exist.
        }

        try {
            Schema::table('tblLeaveApplicationLogs', function (Blueprint $table): void {
                $table->dropIndex('IX_tblLeaveApplicationLogs_application_action_created');
            });
        } catch (\Throwable) {
            // Ignore when index does not exist.
        }

        try {
            Schema::table('tblLeaveApplications', function (Blueprint $table): void {
                $table->dropIndex('IX_tblLeaveApplications_employee_leave_type_status');
            });
        } catch (\Throwable) {
            // Ignore when index does not exist.
        }

        try {
            Schema::table('tblLeaveApplications', function (Blueprint $table): void {
                $table->dropIndex('IX_tblLeaveApplications_status_start_end');
            });
        } catch (\Throwable) {
            // Ignore when index does not exist.
        }

        try {
            Schema::table('tblLeaveBalances', function (Blueprint $table): void {
                $table->dropIndex('IX_tblLeaveBalances_leave_type_employee');
            });
        } catch (\Throwable) {
            // Ignore when index does not exist.
        }

        try {
            Schema::table('tblDepartments', function (Blueprint $table): void {
                $table->dropIndex('IX_tblDepartments_active_name');
            });
        } catch (\Throwable) {
            // Ignore when index does not exist.
        }
    }
};
