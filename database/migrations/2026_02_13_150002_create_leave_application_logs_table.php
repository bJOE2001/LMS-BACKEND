<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Audit trail for leave application workflow actions.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('leave_application_logs', function (Blueprint $table) {
            $table->id();

            $table->foreignId('leave_application_id')
                ->constrained('leave_applications')
                ->cascadeOnDelete();

            $table->enum('action', [
                'SUBMITTED',
                'ADMIN_APPROVED',
                'ADMIN_REJECTED',
                'HR_APPROVED',
                'HR_REJECTED',
            ]);

            $table->enum('performed_by_type', ['EMPLOYEE', 'ADMIN', 'HR']);
            $table->unsignedBigInteger('performed_by_id');
            $table->text('remarks')->nullable();

            $table->timestamp('created_at')->useCurrent();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('leave_application_logs');
    }
};
