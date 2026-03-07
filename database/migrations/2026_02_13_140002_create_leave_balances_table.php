<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Per-employee leave balance for each leave type.
 * LOCAL LMS_DB only.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('leave_balances', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_id')
                ->constrained('employees')
                ->cascadeOnDelete();
            $table->foreignId('leave_type_id')
                ->constrained('leave_types')
                ->cascadeOnDelete();
            $table->decimal('balance', 8, 2)->default(0);
            $table->timestamp('initialized_at')->nullable();
            $table->date('last_accrual_date')->nullable();
            $table->unsignedSmallInteger('year')->nullable();
            $table->timestamps();

            $table->unique(['employee_id', 'leave_type_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('leave_balances');
    }
};
