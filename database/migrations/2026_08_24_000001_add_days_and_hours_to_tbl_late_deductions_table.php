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
        Schema::table('tblLateDeductions', function (Blueprint $table) {
            $table->integer('days_late')->default(0)->after('selected_dates');
            $table->integer('hours_late')->default(0)->after('days_late');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('tblLateDeductions', function (Blueprint $table) {
            $table->dropColumn(['days_late', 'hours_late']);
        });
    }
};
