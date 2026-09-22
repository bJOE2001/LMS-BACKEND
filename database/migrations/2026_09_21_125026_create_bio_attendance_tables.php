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
        if (! Schema::connection('bio')->hasTable('tblBiometricDevices')) {
            Schema::connection('bio')->create('tblBiometricDevices', function (Blueprint $table): void {
                $table->id();
                $table->string('device_name', 100);
                $table->string('serial_number', 50)->unique('UQ_tblBiometricDevices_serial_number');
                $table->string('ip_address', 50)->nullable();
                $table->integer('port')->default(4370);
                $table->string('comm_key', 50)->default('0');
                $table->string('communication_mode', 20)->default('ADMS'); // ADMS, LAN_POLL, OFFLINE_USB
                $table->unsignedBigInteger('department_id')->nullable()->index('IX_tblBiometricDevices_department_id');
                $table->string('department_name', 150)->nullable();
                $table->string('model_name', 50)->default('MB360');
                $table->string('firmware_version', 100)->nullable();
                $table->integer('device_user_count')->default(0);
                $table->integer('device_finger_count')->default(0);
                $table->integer('device_face_count')->default(0);
                $table->integer('device_log_count')->default(0);
                $table->timestamp('last_heartbeat_at')->nullable();
                $table->timestamp('last_sync_at')->nullable();
                $table->string('status', 20)->default('OFFLINE'); // ONLINE, OFFLINE
                $table->boolean('is_active')->default(true);
                $table->timestamps();

                $table->index(['is_active', 'status'], 'IX_tblBiometricDevices_active_status');
            });
        }

        if (! Schema::connection('bio')->hasTable('tblAttendanceRawLogs')) {
            Schema::connection('bio')->create('tblAttendanceRawLogs', function (Blueprint $table): void {
                $table->id();
                $table->string('device_serial_number', 50)->nullable()->index('IX_tblAttendanceRawLogs_device_sn');
                $table->string('biometric_pin', 64)->index('IX_tblAttendanceRawLogs_biometric_pin');
                $table->string('employee_control_no', 64)->nullable()->index('IX_tblAttendanceRawLogs_employee_control_no');
                $table->dateTime('punch_time')->index('IX_tblAttendanceRawLogs_punch_time');
                $table->smallInteger('punch_state')->default(0); // 0: Check-In, 1: Check-Out, 2: Break-Out, 3: Break-In, 4: OT-In, 5: OT-Out
                $table->smallInteger('verify_type')->default(15); // 1: Fingerprint, 15: Face, 4: Card, 0: Password
                $table->string('work_code', 20)->nullable();
                $table->string('sync_source', 20)->default('ADMS'); // ADMS, USB_IMPORT, MANUAL_SYNC
                $table->string('raw_payload', 255)->nullable();
                $table->timestamp('processed_at')->nullable()->index('IX_tblAttendanceRawLogs_processed_at');
                $table->timestamps();

                $table->unique(['biometric_pin', 'punch_time'], 'UQ_tblAttendanceRawLogs_pin_punch_time');
            });
        }

        if (! Schema::connection('bio')->hasTable('tblDailyTimeRecords')) {
            Schema::connection('bio')->create('tblDailyTimeRecords', function (Blueprint $table): void {
                $table->id();
                $table->string('employee_control_no', 64)->index('IX_tblDailyTimeRecords_employee_control_no');
                $table->unsignedBigInteger('department_id')->nullable()->index('IX_tblDailyTimeRecords_department_id');
                $table->date('record_date')->index('IX_tblDailyTimeRecords_record_date');
                $table->string('day_of_week', 15)->nullable();
                $table->time('am_arrival')->nullable();
                $table->time('am_departure')->nullable();
                $table->time('pm_arrival')->nullable();
                $table->time('pm_departure')->nullable();
                $table->time('ot_arrival')->nullable();
                $table->time('ot_departure')->nullable();
                $table->integer('late_minutes')->default(0);
                $table->integer('undertime_minutes')->default(0);
                $table->integer('overtime_minutes')->default(0);
                $table->decimal('rendered_hours', 5, 2)->default(0.00);
                $table->string('status', 30)->default('PRESENT'); // PRESENT, ABSENT, ON_LEAVE, HALF_DAY, HOLIDAY, REST_DAY, OFFICIAL_BUSINESS, PASS_SLIP
                $table->string('leave_type_code', 20)->nullable();
                $table->unsignedBigInteger('leave_application_id')->nullable();
                $table->boolean('is_adjusted')->default(false);
                $table->string('adjustment_reason', 50)->nullable(); // OFFICIAL_BUSINESS, TRAVEL_ORDER, PASS_SLIP, CERTIFICATE_OF_APPEARANCE, MANUAL_OVERRIDE
                $table->text('adjustment_remarks')->nullable();
                $table->unsignedBigInteger('adjusted_by_admin_id')->nullable();
                $table->timestamps();

                $table->unique(['employee_control_no', 'record_date'], 'UQ_tblDailyTimeRecords_control_no_date');
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::connection('bio')->dropIfExists('tblDailyTimeRecords');
        Schema::connection('bio')->dropIfExists('tblAttendanceRawLogs');
        Schema::connection('bio')->dropIfExists('tblBiometricDevices');
    }
};
