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
                $table->string('employee_control_no', 50)->index('IX_tblBiometricTemplates_control_no');
                $table->string('biometric_type', 30)->default('FINGERPRINT'); // FINGERPRINT, FACE, PALM
                $table->smallInteger('finger_id')->default(0); // 0-9 for fingers, 0 for face
                $table->integer('template_size')->nullable();
                $table->smallInteger('valid')->default(1);
                $table->string('template_version', 50)->default('10');
                $table->longText('template_data')->nullable(); // Base64 template string
                $table->text('raw_payload')->nullable();
                $table->string('source_device_sn', 50)->nullable()->index('IX_tblBiometricTemplates_source_sn');
                $table->timestamps();

                $table->unique(['employee_control_no', 'biometric_type', 'finger_id'], 'UQ_tblBiometricTemplates_pin_type_idx');
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::connection('bio')->dropIfExists('tblBiometricTemplates');
    }
};
