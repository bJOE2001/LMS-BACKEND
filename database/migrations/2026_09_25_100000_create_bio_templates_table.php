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
                $table->string('biometric_pin', 50)->index('IX_tblBiometricTemplates_pin');
                $table->string('template_type', 30)->default('FP'); // FP, FACE, BIODATA, BIOPHOTO
                $table->unsignedTinyInteger('finger_index')->nullable(); // 0-9 for FP, null for Face
                $table->longText('template_data');
                $table->unsignedInteger('template_size')->default(0);
                $table->string('template_version', 30)->default('10.0');
                $table->unsignedTinyInteger('valid')->default(1);
                $table->string('source_device_sn', 50)->nullable()->index('IX_tblBiometricTemplates_device_sn');
                $table->timestamps();

                $table->unique(
                    ['employee_control_no', 'template_type', 'finger_index'],
                    'UQ_tblBiometricTemplates_emp_type_idx'
                );
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
