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
        // Drop dependent table first.
        Schema::dropIfExists('tblEmployeeCredentialHistories');
        Schema::dropIfExists('tblEmployeeAccounts');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::create('tblEmployeeAccounts', function (Blueprint $table) {
            $table->id();
            $table->string('employee_id');
            $table->foreign('employee_id')
                ->references('control_no')
                ->on('tblEmployees')
                ->cascadeOnDelete();
            $table->string('username')->unique();
            $table->string('password');
            $table->boolean('must_change_password')->default(true);
            $table->timestamps();
        });

        Schema::create('tblEmployeeCredentialHistories', function (Blueprint $table) {
            $table->id();
            $table->string('employee_id');
            $table->foreign('employee_id')
                ->references('control_no')
                ->on('tblEmployees')
                ->cascadeOnDelete();
            $table->foreignId('employee_account_id')
                ->nullable()
                ->constrained('tblEmployeeAccounts')
                ->noActionOnDelete();
            $table->foreignId('hr_id')
                ->constrained('tblHRAccounts')
                ->cascadeOnDelete();
            $table->string('previous_username')->nullable();
            $table->string('new_username');
            $table->string('generated_password_hash');
            $table->enum('action', ['INITIAL_GENERATION', 'FORGOT_PASSWORD_REGENERATION']);
            $table->timestamp('generated_at')->useCurrent();
            $table->timestamps();

            $table->index(['employee_id', 'generated_at']);
            $table->index(['hr_id', 'generated_at']);
        });
    }
};
