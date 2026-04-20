<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        if (Schema::hasTable('tblLeaveBalances')) {
            DB::statement('ALTER TABLE tblLeaveBalances ALTER COLUMN balance DECIMAL(9,3) NOT NULL');
        }

        if (Schema::hasTable('tblLeaveBalanceCreditHistories')) {
            DB::statement('ALTER TABLE tblLeaveBalanceCreditHistories ALTER COLUMN credits_added DECIMAL(9,3) NOT NULL');
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('tblLeaveBalances')) {
            DB::statement('ALTER TABLE tblLeaveBalances ALTER COLUMN balance DECIMAL(8,2) NOT NULL');
        }

        if (Schema::hasTable('tblLeaveBalanceCreditHistories')) {
            DB::statement('ALTER TABLE tblLeaveBalanceCreditHistories ALTER COLUMN credits_added DECIMAL(8,2) NOT NULL');
        }
    }
};
