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

        if (Schema::hasColumn('tblLeaveApplications', 'details_of_leave')) {
            return;
        }

        Schema::table('tblLeaveApplications', function (Blueprint $table): void {
            $table->text('details_of_leave')->nullable();
        });
    }

    public function down(): void
    {
        if (!Schema::hasTable('tblLeaveApplications')) {
            return;
        }

        if (!Schema::hasColumn('tblLeaveApplications', 'details_of_leave')) {
            return;
        }

        Schema::table('tblLeaveApplications', function (Blueprint $table): void {
            $table->dropColumn('details_of_leave');
        });
    }
};
