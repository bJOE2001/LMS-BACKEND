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

        if (Schema::hasColumn('tblLeaveApplications', 'certification_leave_credits_snapshot')) {
            return;
        }

        Schema::table('tblLeaveApplications', function (Blueprint $table): void {
            $table->longText('certification_leave_credits_snapshot')->nullable();
        });
    }

    public function down(): void
    {
        if (!Schema::hasTable('tblLeaveApplications')) {
            return;
        }

        if (!Schema::hasColumn('tblLeaveApplications', 'certification_leave_credits_snapshot')) {
            return;
        }

        Schema::table('tblLeaveApplications', function (Blueprint $table): void {
            $table->dropColumn('certification_leave_credits_snapshot');
        });
    }
};

