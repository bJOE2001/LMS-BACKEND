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
        // Defensive cleanup for environments that may still have old FKs.
        if (Schema::hasTable('tblLeaveBalances')) {
            try {
                Schema::table('tblLeaveBalances', function (Blueprint $table): void {
                    $table->dropForeign(['employee_control_no']);
                });
            } catch (\Throwable) {
                // Foreign key may not exist in this environment.
            }
        }

        if (Schema::hasTable('tblCOCApplications')) {
            try {
                Schema::table('tblCOCApplications', function (Blueprint $table): void {
                    $table->dropForeign(['employee_control_no']);
                });
            } catch (\Throwable) {
                // Foreign key may not exist in this environment.
            }
        }

        Schema::dropIfExists('tblEmployees');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Intentionally left as no-op.
        // tblEmployees snapshot is deprecated and should not be recreated.
    }
};
