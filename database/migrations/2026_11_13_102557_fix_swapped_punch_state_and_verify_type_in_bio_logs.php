<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Swap previously reversed verify_type / punch_state bytes on LAN_POLL rows.
     */
    public function up(): void
    {
        if (! Schema::connection('bio')->hasTable('tblAttendanceLogs')) {
            return;
        }

        DB::connection('bio')->statement(
            "UPDATE tblAttendanceLogs
             SET punch_state = verify_type, verify_type = punch_state
             WHERE sync_source = 'LAN_POLL'
               AND verify_type = 255
               AND punch_state IN (0, 1, 4, 15)"
        );
    }

    /**
     * Reverse the swap back if rolling back the migration.
     */
    public function down(): void
    {
        if (! Schema::connection('bio')->hasTable('tblAttendanceLogs')) {
            return;
        }

        DB::connection('bio')->statement(
            "UPDATE tblAttendanceLogs
             SET punch_state = verify_type, verify_type = punch_state
             WHERE sync_source = 'LAN_POLL'
               AND punch_state = 255
               AND verify_type IN (0, 1, 4, 15)"
        );
    }
};
