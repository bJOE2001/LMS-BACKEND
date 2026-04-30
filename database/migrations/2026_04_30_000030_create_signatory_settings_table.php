<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        if (!Schema::hasTable('tblSignatorySettings')) {
            Schema::create('tblSignatorySettings', function (Blueprint $table): void {
                $table->id();
                $table->string('signatory_key')->unique();
                $table->string('employee_control_no')->nullable();
                $table->string('signatory_name')->nullable();
                $table->string('signatory_position')->nullable();
                $table->foreignId('updated_by_hr_account_id')
                    ->nullable()
                    ->constrained('tblHRAccounts')
                    ->nullOnDelete();
                $table->timestamps();

                $table->index('employee_control_no', 'IX_tblSignatorySettings_employee_control_no');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('tblSignatorySettings');
    }
};

