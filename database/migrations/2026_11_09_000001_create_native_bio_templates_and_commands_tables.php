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
        if (! Schema::connection('bio')->hasTable('tblBiometricTemplates')) {
            Schema::connection('bio')->create('tblBiometricTemplates', function (Blueprint $table): void {
                $table->id();
                $table->string('employee_control_no', 50)->index('IX_tblBioTemplates_control_no');
                $table->string('communication_mode', 30)->default('ADMS')->index('IX_tblBioTemplates_comm_mode');
                $table->string('biometric_type', 30)->default('FINGERPRINT');
                $table->smallInteger('finger_id')->default(0);
                $table->integer('template_size')->nullable();
                $table->smallInteger('valid')->default(1);
                $table->string('template_version', 50)->default('10');
                $table->longText('template_data')->nullable();
                $table->longText('raw_payload')->nullable();
                $table->string('source_device_sn', 50)->nullable()->index('IX_tblBioTemplates_source_sn');
                $table->timestamps();

                $table->unique(
                    ['employee_control_no', 'communication_mode', 'biometric_type', 'finger_id'],
                    'UQ_tblBioTemplates_pin_mode_type_idx'
                );
            });
        }

        if (! Schema::connection('bio')->hasTable('tblBiometricDeviceCommands')) {
            Schema::connection('bio')->create('tblBiometricDeviceCommands', function (Blueprint $table): void {
                $table->id();
                $table->string('device_serial_number', 50)->index('IX_tblBioDevCmds_device_sn');
                $table->string('command_type', 40)->default('DATA_USER');
                $table->longText('command_payload');
                $table->string('employee_control_no', 50)->nullable()->index('IX_tblBioDevCmds_control_no');
                $table->string('employee_name', 150)->nullable();
                $table->string('status', 20)->default('PENDING')->index('IX_tblBioDevCmds_status');
                $table->integer('return_code')->nullable();
                $table->text('response_payload')->nullable();
                $table->dateTime('sent_at')->nullable();
                $table->dateTime('executed_at')->nullable();
                $table->unsignedBigInteger('created_by_user_id')->nullable();
                $table->timestamps();

                $table->index(['device_serial_number', 'status'], 'IX_tblBioDevCmds_sn_status');
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::connection('bio')->dropIfExists('tblBiometricDeviceCommands');
        Schema::connection('bio')->dropIfExists('tblBiometricTemplates');
    }
};
