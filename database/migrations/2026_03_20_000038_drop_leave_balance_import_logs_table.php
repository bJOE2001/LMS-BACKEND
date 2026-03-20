<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::dropIfExists('tblLeaveBalanceImportLogs');
    }

    public function down(): void
    {
        if (Schema::hasTable('tblLeaveBalanceImportLogs')) {
            return;
        }

        Schema::create('tblLeaveBalanceImportLogs', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('hr_id')->constrained('tblHRAccounts')->cascadeOnDelete();
            $table->string('filename');
            $table->unsignedInteger('total_records')->default(0);
            $table->unsignedInteger('successful_records')->default(0);
            $table->unsignedInteger('failed_records')->default(0);
            $table->timestamp('created_at')->useCurrent();
        });
    }
};

