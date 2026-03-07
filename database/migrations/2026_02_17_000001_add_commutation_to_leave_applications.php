<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tblLeaveApplications', function (Blueprint $table) {
            $table->string('commutation', 32)->nullable()->after('selected_dates');
        });
    }

    public function down(): void
    {
        Schema::table('tblLeaveApplications', function (Blueprint $table) {
            $table->dropColumn('commutation');
        });
    }
};
