<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('tblLeaveRestorations', function (Blueprint $table) {
            if (Schema::hasColumn('tblLeaveRestorations', 'leave_application_id')) {
                $table->dropForeign(['leave_application_id']);
                $table->dropColumn('leave_application_id');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('tblLeaveRestorations', function (Blueprint $table) {
            if (! Schema::hasColumn('tblLeaveRestorations', 'leave_application_id')) {
                $table->foreignId('leave_application_id')
                    ->nullable()
                    ->after('employee_control_no')
                    ->constrained('tblLeaveApplications')
                    ->nullOnDelete();
            }
        });
    }
};
