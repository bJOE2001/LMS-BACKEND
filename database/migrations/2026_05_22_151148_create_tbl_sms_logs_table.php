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
        Schema::create('tblSmsLogs', function (Blueprint $table): void {
            $table->id();
            $table->string('employee_control_no', 64);
            $table->string('message_type', 32);
            $table->foreignId('leave_application_id')
                ->nullable()
                ->constrained('tblLeaveApplications')
                ->nullOnDelete();
            $table->foreignId('coc_application_id')
                ->nullable()
                ->constrained('tblCOCApplications')
                ->nullOnDelete();
            $table->string('destination', 32)->nullable();
            $table->longText('message');
            $table->string('status', 16)->default('PENDING');
            $table->unsignedSmallInteger('gateway_http_status')->nullable();
            $table->longText('gateway_response')->nullable();
            $table->text('error_message')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->timestamps();

            $table->index(['employee_control_no', 'created_at'], 'IX_tblSmsLogs_employee_created_at');
            $table->index(['status', 'created_at'], 'IX_tblSmsLogs_status_created_at');
            $table->index(['message_type', 'created_at'], 'IX_tblSmsLogs_type_created_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('tblSmsLogs');
    }
};
