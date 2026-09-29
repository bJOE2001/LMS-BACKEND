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
        if (Schema::connection('bio')->hasTable('tblDailyTimeRecords')) {
            Schema::connection('bio')->table('tblDailyTimeRecords', function (Blueprint $table): void {
                if (! Schema::connection('bio')->hasColumn('tblDailyTimeRecords', 'am_arrival_device_sn')) {
                    $table->string('am_arrival_device_sn', 50)->nullable()->after('am_arrival');
                }
                if (! Schema::connection('bio')->hasColumn('tblDailyTimeRecords', 'am_departure_device_sn')) {
                    $table->string('am_departure_device_sn', 50)->nullable()->after('am_departure');
                }
                if (! Schema::connection('bio')->hasColumn('tblDailyTimeRecords', 'pm_arrival_device_sn')) {
                    $table->string('pm_arrival_device_sn', 50)->nullable()->after('pm_arrival');
                }
                if (! Schema::connection('bio')->hasColumn('tblDailyTimeRecords', 'pm_departure_device_sn')) {
                    $table->string('pm_departure_device_sn', 50)->nullable()->after('pm_departure');
                }
                if (! Schema::connection('bio')->hasColumn('tblDailyTimeRecords', 'ot_arrival_device_sn')) {
                    $table->string('ot_arrival_device_sn', 50)->nullable()->after('ot_arrival');
                }
                if (! Schema::connection('bio')->hasColumn('tblDailyTimeRecords', 'ot_departure_device_sn')) {
                    $table->string('ot_departure_device_sn', 50)->nullable()->after('ot_departure');
                }
            });
        }

        if (Schema::connection('bio')->hasTable('tblBiometricEnrollments')) {
            Schema::connection('bio')->table('tblBiometricEnrollments', function (Blueprint $table): void {
                if (! Schema::connection('bio')->hasColumn('tblBiometricEnrollments', 'biometric_template')) {
                    $table->longText('biometric_template')->nullable()->after('status');
                }
                if (! Schema::connection('bio')->hasColumn('tblBiometricEnrollments', 'template_type')) {
                    $table->string('template_type', 50)->nullable()->after('biometric_template');
                }
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (Schema::connection('bio')->hasTable('tblDailyTimeRecords')) {
            Schema::connection('bio')->table('tblDailyTimeRecords', function (Blueprint $table): void {
                $columns = [
                    'am_arrival_device_sn',
                    'am_departure_device_sn',
                    'pm_arrival_device_sn',
                    'pm_departure_device_sn',
                    'ot_arrival_device_sn',
                    'ot_departure_device_sn',
                ];
                foreach ($columns as $column) {
                    if (Schema::connection('bio')->hasColumn('tblDailyTimeRecords', $column)) {
                        $table->dropColumn($column);
                    }
                }
            });
        }

        if (Schema::connection('bio')->hasTable('tblBiometricEnrollments')) {
            Schema::connection('bio')->table('tblBiometricEnrollments', function (Blueprint $table): void {
                if (Schema::connection('bio')->hasColumn('tblBiometricEnrollments', 'biometric_template')) {
                    $table->dropColumn('biometric_template');
                }
                if (Schema::connection('bio')->hasColumn('tblBiometricEnrollments', 'template_type')) {
                    $table->dropColumn('template_type');
                }
            });
        }
    }
};
