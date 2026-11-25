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
        if (! Schema::connection('bio')->hasTable('tblAttendanceLogs')) {
            return;
        }

        if (! Schema::connection('bio')->hasColumn('tblAttendanceLogs', 'work_code')) {
            return;
        }

        Schema::connection('bio')->table('tblAttendanceLogs', function (Blueprint $table): void {
            $table->dropColumn('work_code');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (! Schema::connection('bio')->hasTable('tblAttendanceLogs')) {
            return;
        }

        if (Schema::connection('bio')->hasColumn('tblAttendanceLogs', 'work_code')) {
            return;
        }

        Schema::connection('bio')->table('tblAttendanceLogs', function (Blueprint $table): void {
            $table->string('work_code', 20)->nullable()->after('verify_type');
        });
    }
};
