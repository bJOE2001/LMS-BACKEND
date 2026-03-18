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
        Schema::create('tblCOCApplications', function (Blueprint $table): void {
            $table->id();
            $table->string('erms_control_no');
            $table->foreign('erms_control_no')
                ->references('control_no')
                ->on('tblEmployees')
                ->noActionOnDelete();
            $table->enum('status', ['PENDING', 'APPROVED', 'REJECTED'])->default('PENDING');
            $table->unsignedInteger('total_minutes');
            $table->text('remarks')->nullable();
            $table->timestamp('submitted_at')->useCurrent();
            $table->timestamps();

            $table->index(['erms_control_no', 'status']);
            $table->index(['erms_control_no', 'created_at']);
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
    }
};
