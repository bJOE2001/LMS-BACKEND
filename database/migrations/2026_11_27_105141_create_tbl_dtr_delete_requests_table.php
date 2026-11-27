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
        if (! Schema::connection('bio')->hasTable('tblDtrDeleteRequests')) {
            Schema::connection('bio')->create('tblDtrDeleteRequests', function (Blueprint $table): void {
                $table->id();
                $table->unsignedBigInteger('daily_time_record_id')->nullable()->index();
                $table->string('employee_control_no', 50)->index();
                $table->string('employee_name', 255)->nullable();
                $table->unsignedBigInteger('department_id')->nullable()->index();
                $table->string('office_name', 255)->nullable();
                $table->date('record_date')->index();
                $table->string('period', 20);
                $table->text('slots');
                $table->text('previous_snapshot');
                $table->string('reason', 500);
                $table->string('status', 20)->default('pending')->index();
                $table->unsignedBigInteger('requested_by_admin_id')->nullable()->index();
                $table->string('requested_by_name', 255)->nullable();
                $table->unsignedBigInteger('reviewed_by_hr_id')->nullable();
                $table->string('reviewed_by_name', 255)->nullable();
                $table->timestamp('reviewed_at')->nullable();
                $table->string('hr_remarks', 500)->nullable();
                $table->timestamps();
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (Schema::connection('bio')->hasTable('tblDtrDeleteRequests')) {
            Schema::connection('bio')->dropIfExists('tblDtrDeleteRequests');
        }
    }
};
