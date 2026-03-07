<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Civil Service Form No. 6 — Application for Leave.
 * One row per leave application; employee_id = applicant (from employee_accounts).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('leave_applications', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_id')
                ->constrained('employees')
                ->cascadeOnDelete();

            $table->string('office')->nullable();
            $table->string('last_name');
            $table->string('first_name');
            $table->string('position')->nullable();
            $table->string('salary')->nullable();

            $table->string('leave_type');
            $table->string('leave_type_other')->nullable();
            $table->string('vacation_detail')->nullable();
            $table->string('vacation_specify')->nullable();
            $table->string('sick_detail')->nullable();
            $table->string('sick_specify')->nullable();
            $table->string('women_specify')->nullable();
            $table->string('study_detail')->nullable();
            $table->string('other_purpose')->nullable();

            $table->unsignedSmallInteger('days');
            $table->date('start_date');
            $table->date('end_date');
            $table->string('commutation', 50)->default('Not Requested');
            $table->text('reason');

            $table->enum('status', ['pending', 'approved', 'rejected', 'cancelled'])->default('pending');
            $table->timestamp('reviewed_at')->nullable();
            $table->unsignedBigInteger('reviewed_by')->nullable();
            $table->text('remarks')->nullable();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('leave_applications');
    }
};
