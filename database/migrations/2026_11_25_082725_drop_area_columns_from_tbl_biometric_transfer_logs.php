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
        if (! Schema::connection('bio')->hasTable('tblBiometricTransferLogs')) {
            return;
        }

        $columnsToDrop = [];
        if (Schema::connection('bio')->hasColumn('tblBiometricTransferLogs', 'source_area_id')) {
            $columnsToDrop[] = 'source_area_id';
        }
        if (Schema::connection('bio')->hasColumn('tblBiometricTransferLogs', 'target_area_id')) {
            $columnsToDrop[] = 'target_area_id';
        }

        if ($columnsToDrop === []) {
            return;
        }

        Schema::connection('bio')->table('tblBiometricTransferLogs', function (Blueprint $table) use ($columnsToDrop): void {
            $table->dropColumn($columnsToDrop);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (! Schema::connection('bio')->hasTable('tblBiometricTransferLogs')) {
            return;
        }

        $hasSourceArea = Schema::connection('bio')->hasColumn('tblBiometricTransferLogs', 'source_area_id');
        $hasTargetArea = Schema::connection('bio')->hasColumn('tblBiometricTransferLogs', 'target_area_id');

        if ($hasSourceArea && $hasTargetArea) {
            return;
        }

        Schema::connection('bio')->table('tblBiometricTransferLogs', function (Blueprint $table) use ($hasSourceArea, $hasTargetArea): void {
            if (! $hasSourceArea) {
                $table->integer('source_area_id')->nullable()->after('source_device_sn');
            }
            if (! $hasTargetArea) {
                $table->integer('target_area_id')->nullable()->after('target_device_sn');
            }
        });
    }
};
