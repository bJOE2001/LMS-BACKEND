<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // Department heads
        Schema::create('tblDepartmentHeads', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('department_id')
                ->unique()
                ->constrained('tblDepartments')
                ->cascadeOnDelete();
            $table->string('control_no')->nullable();
            $table->string('surname')->nullable();
            $table->string('firstname')->nullable();
            $table->string('middlename')->nullable();
            $table->string('office')->nullable();
            $table->string('status')->nullable();
            $table->string('designation')->nullable();
            $table->decimal('rate_mon', 10, 2)->nullable();
            $table->string('full_name');
            $table->string('position')->nullable();
            $table->timestamps();

            $table->index('control_no');
        });

        // COC applications
        Schema::create('tblCOCApplications', function (Blueprint $table): void {
            $table->id();
            $table->string('erms_control_no');
            $table->foreign('erms_control_no')
                ->references('control_no')
                ->on('tblEmployees')
                ->noActionOnDelete();
            $table->string('status', 16)->default('PENDING');
            $table->foreignId('reviewed_by_admin_id')
                ->nullable()
                ->constrained('tblDepartmentAdmins')
                ->noActionOnDelete();
            $table->timestamp('admin_reviewed_at')->nullable();
            $table->foreignId('reviewed_by_hr_id')
                ->nullable()
                ->constrained('tblHRAccounts')
                ->noActionOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->foreignId('cto_leave_type_id')
                ->nullable()
                ->constrained('tblLeaveTypes')
                ->noActionOnDelete();
            $table->decimal('cto_credited_days', 8, 2)->nullable();
            $table->timestamp('cto_credited_at')->nullable();
            $table->unsignedInteger('total_minutes');
            $table->text('remarks')->nullable();
            $table->timestamp('submitted_at')->useCurrent();
            $table->timestamps();

            $table->index(['erms_control_no', 'status']);
            $table->index(['erms_control_no', 'created_at']);
            $table->index(['status', 'created_at'], 'ix_tblcocapplications_status_created_at');
            $table->index(['reviewed_by_admin_id', 'admin_reviewed_at'], 'ix_tblcocapplications_admin_review');
        });

        Schema::create('tblCOCApplicationRows', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('coc_application_id')
                ->constrained('tblCOCApplications')
                ->cascadeOnDelete();
            $table->unsignedInteger('line_no');
            $table->date('overtime_date');
            $table->text('nature_of_overtime');
            $table->string('time_from', 5);
            $table->string('time_to', 5);
            $table->unsignedInteger('minutes');
            $table->unsignedInteger('cumulative_minutes');
            $table->timestamps();

            $table->index(['coc_application_id', 'line_no']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('tblCOCApplicationRows');
        Schema::dropIfExists('tblCOCApplications');
        Schema::dropIfExists('tblDepartmentHeads');
    }
};
