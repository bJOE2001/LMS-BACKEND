<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        if (!Schema::hasTable('tblLeaveTypes')) {
            return;
        }

        $timestamp = now();

        DB::table('tblLeaveTypes')->updateOrInsert(
            ['name' => 'CTO Leave'],
            [
                'category' => 'EVENT',
                'accrual_rate' => null,
                'accrual_day_of_month' => null,
                'max_days' => null,
                'is_credit_based' => true,
                'resets_yearly' => false,
                'requires_documents' => false,
                'description' => 'Compensatory Time Off credits converted from approved COC applications.',
                'updated_at' => $timestamp,
                'created_at' => $timestamp,
            ]
        );
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (!Schema::hasTable('tblLeaveTypes')) {
            return;
        }

        DB::table('tblLeaveTypes')
            ->where('name', 'CTO Leave')
            ->delete();
    }
};
