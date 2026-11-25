<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        if (! Schema::connection('bio')->hasTable('tblBiometricTransferLogs')) {
            return;
        }

        DB::connection('bio')->table('tblBiometricTransferLogs')
            ->where('action_type', 'ASSIGN')
            ->update(['action_type' => 'TRANSFER']);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Merged historical ASSIGN entries cannot be unambiguously separated back from TRANSFER.
    }
};
