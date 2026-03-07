<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('notifications', function (Blueprint $table) {
            $table->id();

            // Polymorphic recipient — can be EmployeeAccount, DepartmentAdmin, or HRAccount
            $table->string('notifiable_type');
            $table->unsignedBigInteger('notifiable_id');

            $table->string('type', 50);        // leave_approved, leave_rejected, leave_request, leave_pending, reminder, system
            $table->string('title');
            $table->text('message');

            // Optional reference to the leave application that triggered this notification
            $table->unsignedBigInteger('leave_application_id')->nullable();

            $table->timestamp('read_at')->nullable();
            $table->timestamps();

            $table->index(['notifiable_type', 'notifiable_id']);
            $table->index('read_at');

            $table->foreign('leave_application_id')
                ->references('id')
                ->on('leave_applications')
                ->onDelete('cascade');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notifications');
    }
};
