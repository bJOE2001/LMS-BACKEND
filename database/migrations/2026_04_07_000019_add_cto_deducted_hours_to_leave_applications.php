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

        Schema::table('tblLeaveApplications', function (Blueprint $table): void {
            if (!Schema::hasColumn('tblLeaveApplications', 'cto_deducted_hours')) {
                $table->decimal('cto_deducted_hours', 8, 2)->nullable()->after('deductible_days');
            }
        });
    }

    public function down(): void
    {
        if (!Schema::hasTable('tblLeaveApplications')) {
            return;
        }

        Schema::table('tblLeaveApplications', function (Blueprint $table): void {
            if (Schema::hasColumn('tblLeaveApplications', 'cto_deducted_hours')) {
                $table->dropColumn('cto_deducted_hours');
            }
        });
    }
};
