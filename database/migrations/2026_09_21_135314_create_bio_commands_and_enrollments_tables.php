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
        if (Schema::connection('bio')->hasTable('tblBiometricDeviceCommands')) {
            Schema::connection('bio')->dropIfExists('tblBiometricDeviceCommands');
        }

        if (! Schema::connection('bio')->hasTable('tblBiometricEnrollments')) {
            Schema::connection('bio')->create('tblBiometricEnrollments', function (Blueprint $table): void {
                $table->id();
                $table->string('employee_control_no', 50)->unique('UQ_tblBiometricEnrollments_control_no');
                $table->string('employee_name', 150);
                $table->string('status', 30)->default('PENDING_ENROLLMENT'); // REGISTERED, PENDING_ENROLLMENT
                $table->string('enrollment_device_sn', 50)->nullable()->index('IX_tblBiometricEnrollments_device_sn');
                $table->dateTime('enrolled_at')->nullable();
                $table->unsignedBigInteger('enrolled_by_user_id')->nullable();
                $table->text('synced_devices')->nullable(); // JSON array of device serial numbers
                $table->string('notes', 255)->nullable();
                $table->timestamps();

                $table->index(['status'], 'IX_tblBiometricEnrollments_status');
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::connection('bio')->dropIfExists('tblBiometricDeviceCommands');
        Schema::connection('bio')->dropIfExists('tblBiometricEnrollments');
    }
};
