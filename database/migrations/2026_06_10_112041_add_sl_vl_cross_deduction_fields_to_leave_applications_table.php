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
        if (! Schema::hasTable('tblLeaveApplications')) {
            return;
        }

        Schema::table('tblLeaveApplications', function (Blueprint $table) {
            if (! Schema::hasColumn('tblLeaveApplications', 'linked_sick_leave_deducted_days')) {
                $table->decimal('linked_sick_leave_deducted_days', total: 6, places: 3)
                    ->nullable()
                    ->after('linked_vacation_leave_deducted_days');
            }

            if (! Schema::hasColumn('tblLeaveApplications', 'allow_sl_vl_cross_deduction')) {
                $table->boolean('allow_sl_vl_cross_deduction')
                    ->default(false)
                    ->after('pay_mode');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (! Schema::hasTable('tblLeaveApplications')) {
            return;
        }

        Schema::table('tblLeaveApplications', function (Blueprint $table) {
            if (Schema::hasColumn('tblLeaveApplications', 'allow_sl_vl_cross_deduction')) {
                $table->dropColumn('allow_sl_vl_cross_deduction');
            }

            if (Schema::hasColumn('tblLeaveApplications', 'linked_sick_leave_deducted_days')) {
                $table->dropColumn('linked_sick_leave_deducted_days');
            }
        });
    }
};
