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
        Schema::create('tblLeaveRestorations', function (Blueprint $table) {
            $table->id();
            $table->string('employee_control_no', 64)->index('IX_tblLeaveRestorations_employee_control_no');
            $table->foreignId('leave_application_id')
                ->nullable()
                ->constrained('tblLeaveApplications')
                ->nullOnDelete();
            $table->foreignId('target_leave_type_id')
                ->constrained('tblLeaveTypes');
            $table->string('restoration_reason_category', 64)->default('SPECIAL_BENEFIT');
            $table->string('restoration_reason_details')->nullable();
            $table->date('start_date');
            $table->date('end_date');
            $table->json('selected_dates')->nullable();
            $table->decimal('restored_days', 8, 3)->default(0.000);
            $table->unsignedBigInteger('restored_by_hr_id')->nullable();
            $table->text('remarks')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('tblLeaveRestorations');
    }
};
