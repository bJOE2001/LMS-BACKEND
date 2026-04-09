<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        if (!Schema::hasTable('tblLeaveApplications')) {
            return;
        }

        if (Schema::hasColumn('tblLeaveApplications', 'selected_date_half_day_portion')) {
            return;
        }

        Schema::table('tblLeaveApplications', function (Blueprint $table): void {
            $table->longText('selected_date_half_day_portion')->nullable();
        });
    }

    public function down(): void
    {
        if (!Schema::hasTable('tblLeaveApplications')) {
            return;
        }

        if (!Schema::hasColumn('tblLeaveApplications', 'selected_date_half_day_portion')) {
            return;
        }

        Schema::table('tblLeaveApplications', function (Blueprint $table): void {
            $table->dropColumn('selected_date_half_day_portion');
        });
    }
};
