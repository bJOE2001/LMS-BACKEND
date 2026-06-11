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
            if (! Schema::hasColumn('tblLeaveApplications', 'without_pay_days')) {
                $table->decimal('without_pay_days', total: 6, places: 3)
                    ->nullable()
                    ->after('deductible_days');
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
            if (Schema::hasColumn('tblLeaveApplications', 'without_pay_days')) {
                $table->dropColumn('without_pay_days');
            }
        });
    }
};
