<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Leave type classification: ACCRUED, RESETTABLE, EVENT.
 * LOCAL LMS_DB only.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('leave_types', function (Blueprint $table) {
            $table->id();
            $table->string('name')->unique();
            $table->enum('category', ['ACCRUED', 'RESETTABLE', 'EVENT']);
            $table->decimal('accrual_rate', 5, 2)->nullable();
            $table->unsignedTinyInteger('accrual_day_of_month')->nullable();
            $table->unsignedInteger('max_days')->nullable();
            $table->boolean('is_credit_based')->default(false);
            $table->boolean('resets_yearly')->default(false);
            $table->boolean('requires_documents')->default(false);
            $table->text('description')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('leave_types');
    }
};
