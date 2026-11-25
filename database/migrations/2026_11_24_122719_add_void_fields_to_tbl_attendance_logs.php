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

        $hasVoidedAt = Schema::connection('bio')->hasColumn('tblAttendanceLogs', 'voided_at');
        $hasVoidedBy = Schema::connection('bio')->hasColumn('tblAttendanceLogs', 'voided_by_hr_account_id');
        $hasVoidReason = Schema::connection('bio')->hasColumn('tblAttendanceLogs', 'void_reason');

        if ($hasVoidedAt && $hasVoidedBy && $hasVoidReason) {
            return;
        }

        Schema::connection('bio')->table('tblAttendanceLogs', function (Blueprint $table) use ($hasVoidedAt, $hasVoidedBy, $hasVoidReason): void {
            if (! $hasVoidedAt) {
                $table->timestamp('voided_at')->nullable()->after('processed_at')->index('IX_tblAttendanceLogs_voided_at');
            }

            if (! $hasVoidedBy) {
                $table->unsignedBigInteger('voided_by_hr_account_id')->nullable()->after('voided_at');
            }

            if (! $hasVoidReason) {
                $table->string('void_reason', 255)->nullable()->after('voided_by_hr_account_id');
            }
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

        $hasVoidedAt = Schema::connection('bio')->hasColumn('tblAttendanceLogs', 'voided_at');
        $hasVoidedBy = Schema::connection('bio')->hasColumn('tblAttendanceLogs', 'voided_by_hr_account_id');
        $hasVoidReason = Schema::connection('bio')->hasColumn('tblAttendanceLogs', 'void_reason');

        $columnsToDrop = [];
        if ($hasVoidedAt) {
            $columnsToDrop[] = 'voided_at';
        }
        if ($hasVoidedBy) {
            $columnsToDrop[] = 'voided_by_hr_account_id';
        }
        if ($hasVoidReason) {
            $columnsToDrop[] = 'void_reason';
        }

        if ($columnsToDrop === []) {
            return;
        }

        Schema::connection('bio')->table('tblAttendanceLogs', function (Blueprint $table) use ($hasVoidedAt, $columnsToDrop): void {
            if ($hasVoidedAt) {
                $table->dropIndex('IX_tblAttendanceLogs_voided_at');
            }

            $table->dropColumn($columnsToDrop);
        });
    }
};
