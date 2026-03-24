<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasColumn('tblLeaveApplications', 'recall_effective_date')) {
            Schema::table('tblLeaveApplications', function (Blueprint $table): void {
                $table->date('recall_effective_date')->nullable();
            });
        }

        if (!Schema::hasColumn('tblLeaveApplications', 'recall_selected_dates')) {
            Schema::table('tblLeaveApplications', function (Blueprint $table): void {
                $table->longText('recall_selected_dates')->nullable();
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('tblLeaveApplications', 'recall_selected_dates')) {
            Schema::table('tblLeaveApplications', function (Blueprint $table): void {
                $table->dropColumn('recall_selected_dates');
            });
        }

        if (Schema::hasColumn('tblLeaveApplications', 'recall_effective_date')) {
            Schema::table('tblLeaveApplications', function (Blueprint $table): void {
                $table->dropColumn('recall_effective_date');
            });
        }
    }
};
