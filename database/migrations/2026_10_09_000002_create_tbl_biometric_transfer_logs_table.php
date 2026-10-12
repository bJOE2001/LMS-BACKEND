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
            Schema::connection('bio')->create('tblBiometricTransferLogs', function (Blueprint $table): void {
                $table->id();
                $table->string('employee_control_no', 50)->index('IX_tblBiometricTransferLogs_control_no');
                $table->string('employee_name', 150)->nullable();
                $table->string('action_type', 40)->default('TRANSFER'); // TRANSFER, ASSIGN, REMOVE, REGISTRATION
                $table->unsignedBigInteger('source_department_id')->nullable();
                $table->string('source_office_name', 150)->nullable();
                $table->string('source_device_sn', 50)->nullable();
                $table->integer('source_area_id')->nullable();
                $table->unsignedBigInteger('target_department_id')->nullable();
                $table->string('target_office_name', 150)->nullable();
                $table->string('target_device_sn', 50)->nullable();
                $table->integer('target_area_id')->nullable();
                $table->string('status', 30)->default('SUCCESS'); // SUCCESS, FAILED, PENDING
                $table->text('remarks')->nullable();
                $table->unsignedBigInteger('performed_by_user_id')->nullable();
                $table->string('performed_by_name', 150)->nullable();
                $table->timestamps();

                $table->index(['status'], 'IX_tblBiometricTransferLogs_status');
                $table->index(['action_type'], 'IX_tblBiometricTransferLogs_action_type');
                $table->index(['created_at'], 'IX_tblBiometricTransferLogs_created_at');
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::connection('bio')->dropIfExists('tblBiometricTransferLogs');
    }
};
