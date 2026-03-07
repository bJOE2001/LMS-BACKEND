<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    /**
     * Add monetization columns to leave_applications.
     * Make start_date and end_date nullable (monetization has no date range).
     */
    public function up(): void
    {
        Schema::table('tblLeaveApplications', function (Blueprint $table) {
            $table->boolean('is_monetization')->default(false)->after('selected_dates');
            $table->decimal('equivalent_amount', 12, 2)->nullable()->after('is_monetization');
            $table->date('start_date')->nullable()->change();
            $table->date('end_date')->nullable()->change();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('tblLeaveApplications', function (Blueprint $table) {
            $table->dropColumn(['is_monetization', 'equivalent_amount']);
            $table->date('start_date')->nullable(false)->change();
            $table->date('end_date')->nullable(false)->change();
        });
    }
};
