<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        if (!Schema::hasTable('tblLeaveBalances') || !Schema::hasColumn('tblLeaveBalances', 'initialized_at')) {
            return;
        }

        Schema::table('tblLeaveBalances', function (Blueprint $table): void {
            $table->dropColumn('initialized_at');
        });
    }

    public function down(): void
    {
        if (!Schema::hasTable('tblLeaveBalances') || Schema::hasColumn('tblLeaveBalances', 'initialized_at')) {
            return;
        }

        Schema::table('tblLeaveBalances', function (Blueprint $table): void {
            $table->timestamp('initialized_at')->nullable();
        });
    }
};

