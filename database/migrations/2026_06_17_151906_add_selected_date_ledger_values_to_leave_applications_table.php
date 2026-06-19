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
            if (! Schema::hasColumn('tblLeaveApplications', 'selected_date_deduction')) {
                $table->longText('selected_date_deduction')
                    ->nullable()
                    ->after('selected_date_half_day_portion');
            }

            if (! Schema::hasColumn('tblLeaveApplications', 'selected_date_without_pay')) {
                $table->longText('selected_date_without_pay')
                    ->nullable()
                    ->after('selected_date_deduction');
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
            if (Schema::hasColumn('tblLeaveApplications', 'selected_date_without_pay')) {
                $table->dropColumn('selected_date_without_pay');
            }

            if (Schema::hasColumn('tblLeaveApplications', 'selected_date_deduction')) {
                $table->dropColumn('selected_date_deduction');
            }
        });
    }
};
