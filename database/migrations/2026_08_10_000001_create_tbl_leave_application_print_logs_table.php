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
        Schema::create('tblLeaveApplicationPrintLogs', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('leave_application_id')
                ->constrained('tblLeaveApplications')
                ->cascadeOnDelete();
            $table->string('printed_by_type', 32);
            $table->string('printed_by_id', 64)->nullable();
            $table->string('printed_by_name', 255)->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->text('remarks')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['leave_application_id', 'created_at'], 'IX_tblLeaveAppPrintLogs_app_created');
            $table->index(['printed_by_type', 'printed_by_id'], 'IX_tblLeaveAppPrintLogs_performer');
            $table->index('created_at', 'IX_tblLeaveAppPrintLogs_created_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('tblLeaveApplicationPrintLogs');
    }
};
