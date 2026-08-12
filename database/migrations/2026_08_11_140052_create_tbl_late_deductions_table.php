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
        Schema::create('tblLateDeductions', function (Blueprint $table) {
            $table->id();
            $table->string('employee_control_no', 64)->index('IX_tblLateDeductions_employee_control_no');
            $table->foreignId('target_leave_type_id')->constrained('tblLeaveTypes');
            $table->string('particulars')->nullable();
            $table->date('start_date');
            $table->date('end_date');
            $table->json('selected_dates')->nullable();
            $table->integer('minutes_late')->default(0);
            $table->decimal('deducted_days', 8, 3)->default(0.000);
            $table->unsignedBigInteger('deducted_by_hr_id')->nullable();
            $table->text('remarks')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('tblLateDeductions');
    }
};
