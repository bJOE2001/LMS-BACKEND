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

        Schema::table('tblLeaveApplications', function (Blueprint $table): void {
            if (! Schema::hasColumn('tblLeaveApplications', 'monetization_leave_credits')) {
                $table->longText('monetization_leave_credits')->nullable()->after('equivalent_amount');
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

        Schema::table('tblLeaveApplications', function (Blueprint $table): void {
            if (Schema::hasColumn('tblLeaveApplications', 'monetization_leave_credits')) {
                $table->dropColumn('monetization_leave_credits');
            }
        });
    }
};
