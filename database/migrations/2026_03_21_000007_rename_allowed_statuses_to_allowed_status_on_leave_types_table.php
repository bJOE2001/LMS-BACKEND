<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $hasPlural = Schema::hasColumn('tblLeaveTypes', 'allowed_statuses');
        $hasSingular = Schema::hasColumn('tblLeaveTypes', 'allowed_status');

        if ($hasPlural && !$hasSingular) {
            DB::statement("EXEC sp_rename 'dbo.tblLeaveTypes.allowed_statuses', 'allowed_status', 'COLUMN'");
            return;
        }

        if ($hasPlural && $hasSingular) {
            DB::table('tblLeaveTypes')
                ->whereNull('allowed_status')
                ->update([
                    'allowed_status' => DB::raw('allowed_statuses'),
                ]);

            DB::statement('ALTER TABLE dbo.tblLeaveTypes DROP COLUMN allowed_statuses');
        }
    }

    public function down(): void
    {
        $hasPlural = Schema::hasColumn('tblLeaveTypes', 'allowed_statuses');
        $hasSingular = Schema::hasColumn('tblLeaveTypes', 'allowed_status');

        if ($hasSingular && !$hasPlural) {
            DB::statement("EXEC sp_rename 'dbo.tblLeaveTypes.allowed_status', 'allowed_statuses', 'COLUMN'");
            return;
        }

        if ($hasSingular && $hasPlural) {
            DB::table('tblLeaveTypes')
                ->whereNull('allowed_statuses')
                ->update([
                    'allowed_statuses' => DB::raw('allowed_status'),
                ]);

            DB::statement('ALTER TABLE dbo.tblLeaveTypes DROP COLUMN allowed_status');
        }
    }
};
