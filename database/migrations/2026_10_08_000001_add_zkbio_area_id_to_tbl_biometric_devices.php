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
        if (Schema::connection('bio')->hasTable('tblBiometricDevices')) {
            Schema::connection('bio')->table('tblBiometricDevices', function (Blueprint $table): void {
                if (! Schema::connection('bio')->hasColumn('tblBiometricDevices', 'zkbio_area_id')) {
                    $table->integer('zkbio_area_id')->nullable()->after('department_name');
                }
                if (! Schema::connection('bio')->hasColumn('tblBiometricDevices', 'is_primary')) {
                    $table->boolean('is_primary')->default(true)->after('zkbio_area_id');
                }
            });

            // Seed known physical devices to their respective ZKBio Time Areas and Department IDs
            DB::connection('bio')->table('tblBiometricDevices')
                ->where('serial_number', 'KMY2252000112')
                ->update([
                    'department_id' => 18,
                    'department_name' => 'OFFICE OF THE CITY HUMAN RESOURCE MANAGEMENT OFFICER',
                    'zkbio_area_id' => 3,
                    'is_primary' => true,
                ]);

            DB::connection('bio')->table('tblBiometricDevices')
                ->where('serial_number', 'KMY2252000117')
                ->update([
                    'department_id' => 19,
                    'department_name' => 'OFFICE OF THE CITY INFORMATION AND COMMUNICATIONS TECHNOLOGY MANAGEMENT OFFICER',
                    'zkbio_area_id' => 2,
                    'is_primary' => true,
                ]);
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (Schema::connection('bio')->hasTable('tblBiometricDevices')) {
            Schema::connection('bio')->table('tblBiometricDevices', function (Blueprint $table): void {
                if (Schema::connection('bio')->hasColumn('tblBiometricDevices', 'is_primary')) {
                    $table->dropColumn('is_primary');
                }
                if (Schema::connection('bio')->hasColumn('tblBiometricDevices', 'zkbio_area_id')) {
                    $table->dropColumn('zkbio_area_id');
                }
            });
        }
    }
};
