<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('tblLeaveApplications')) {
            return;
        }

        Schema::table('tblLeaveApplications', function (Blueprint $table): void {
            $columns = [
                'pending_update_payload',
                'pending_update_reason',
                'pending_update_previous_status',
                'pending_update_requested_by',
                'pending_update_requested_at',
            ];

            foreach ($columns as $column) {
                if (Schema::hasColumn('tblLeaveApplications', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }

    public function down(): void
    {
        if (!Schema::hasTable('tblLeaveApplications')) {
            return;
        }

        Schema::table('tblLeaveApplications', function (Blueprint $table): void {
            if (!Schema::hasColumn('tblLeaveApplications', 'pending_update_payload')) {
                $table->longText('pending_update_payload')->nullable()->after('remarks');
            }

            if (!Schema::hasColumn('tblLeaveApplications', 'pending_update_reason')) {
                $table->longText('pending_update_reason')->nullable()->after('pending_update_payload');
            }

            if (!Schema::hasColumn('tblLeaveApplications', 'pending_update_previous_status')) {
                $table->string('pending_update_previous_status', 32)->nullable()->after('pending_update_reason');
            }

            if (!Schema::hasColumn('tblLeaveApplications', 'pending_update_requested_by')) {
                $table->string('pending_update_requested_by', 64)->nullable()->after('pending_update_previous_status');
            }

            if (!Schema::hasColumn('tblLeaveApplications', 'pending_update_requested_at')) {
                $table->timestamp('pending_update_requested_at')->nullable()->after('pending_update_requested_by');
            }
        });
    }
};
