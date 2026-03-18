<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasColumn('tblLeaveApplications', 'pay_mode')) {
            Schema::table('tblLeaveApplications', function (Blueprint $table) {
                $table->string('pay_mode', 8)->default('WP')->after('commutation');
            });
        }

        if (Schema::hasColumn('tblLeaveApplications', 'pay_mode')) {
            DB::table('tblLeaveApplications')
                ->whereNull('pay_mode')
                ->update(['pay_mode' => 'WP']);
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('tblLeaveApplications', 'pay_mode')) {
            Schema::table('tblLeaveApplications', function (Blueprint $table) {
                $table->dropColumn('pay_mode');
            });
        }
    }
};
