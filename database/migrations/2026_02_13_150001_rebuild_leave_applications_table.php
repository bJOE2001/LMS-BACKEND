<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Rebuild leave_applications for multi-step approval workflow:
 * Employee → Department Admin → HR
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::dropIfExists('leave_applications');

        Schema::create('leave_applications', function (Blueprint $table) {
            $table->id();

            $table->foreignId('employee_id')
                ->constrained('employees')
                ->cascadeOnDelete();

            $table->foreignId('leave_type_id')
                ->constrained('leave_types')
                ->cascadeOnDelete();

            $table->date('start_date');
            $table->date('end_date');
            $table->decimal('total_days', 5, 2);
            $table->text('reason')->nullable();

            $table->enum('status', [
                'PENDING_ADMIN',
                'PENDING_HR',
                'APPROVED',
                'REJECTED',
            ])->default('PENDING_ADMIN');

            $table->unsignedBigInteger('admin_id')->nullable();
            $table->unsignedBigInteger('hr_id')->nullable();
            $table->timestamp('admin_approved_at')->nullable();
            $table->timestamp('hr_approved_at')->nullable();
            $table->text('remarks')->nullable();

            $table->timestamps();

            // Indexes for common queries
            $table->index(['employee_id', 'status']);
            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('leave_applications');
    }
};
