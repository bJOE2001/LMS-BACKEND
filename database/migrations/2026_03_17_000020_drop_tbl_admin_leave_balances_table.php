<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        if (!Schema::hasTable('tblAdminLeaveBalances')) {
            return;
        }

        Schema::drop('tblAdminLeaveBalances');
    }

    public function down(): void
    {
        if (Schema::hasTable('tblAdminLeaveBalances')) {
            return;
        }

        Schema::create('tblAdminLeaveBalances', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('admin_id')->constrained('tblDepartmentAdmins')->cascadeOnDelete();
            $table->foreignId('leave_type_id')->constrained('tblLeaveTypes')->cascadeOnDelete();
            $table->decimal('balance', 8, 2)->default(0);
            $table->integer('year');
            $table->timestamp('initialized_at')->nullable();
            $table->timestamps();

            $table->unique(['admin_id', 'leave_type_id', 'year']);
        });
    }
};
