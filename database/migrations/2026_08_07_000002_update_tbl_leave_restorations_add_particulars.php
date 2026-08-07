<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('tblLeaveRestorations', function (Blueprint $table) {
            if (! Schema::hasColumn('tblLeaveRestorations', 'particulars')) {
                $table->string('particulars', 255)->nullable()->after('target_leave_type_id');
            }
        });

        if (Schema::hasColumn('tblLeaveRestorations', 'restoration_reason_details')) {
            DB::statement('UPDATE tblLeaveRestorations SET particulars = restoration_reason_details WHERE particulars IS NULL AND restoration_reason_details IS NOT NULL');
        }

        Schema::table('tblLeaveRestorations', function (Blueprint $table) {
            if (Schema::hasColumn('tblLeaveRestorations', 'restoration_reason_category')) {
                $table->dropColumn('restoration_reason_category');
            }
            if (Schema::hasColumn('tblLeaveRestorations', 'restoration_reason_details')) {
                $table->dropColumn('restoration_reason_details');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('tblLeaveRestorations', function (Blueprint $table) {
            if (! Schema::hasColumn('tblLeaveRestorations', 'restoration_reason_category')) {
                $table->string('restoration_reason_category', 64)->nullable();
            }
            if (! Schema::hasColumn('tblLeaveRestorations', 'restoration_reason_details')) {
                $table->string('restoration_reason_details')->nullable();
            }
            if (Schema::hasColumn('tblLeaveRestorations', 'particulars')) {
                $table->dropColumn('particulars');
            }
        });
    }
};
