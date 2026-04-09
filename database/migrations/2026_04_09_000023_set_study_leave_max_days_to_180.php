<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        if (!Schema::hasTable('tblLeaveTypes') || !Schema::hasColumn('tblLeaveTypes', 'max_days')) {
            return;
        }

        DB::table('tblLeaveTypes')
            ->whereIn(DB::raw('LOWER(LTRIM(RTRIM(name)))'), ['study leave', 'rehabilitation leave'])
            ->update([
                'max_days' => 180,
                'updated_at' => now(),
            ]);
    }

    public function down(): void
    {
        if (!Schema::hasTable('tblLeaveTypes') || !Schema::hasColumn('tblLeaveTypes', 'max_days')) {
            return;
        }

        DB::table('tblLeaveTypes')
            ->whereIn(DB::raw('LOWER(LTRIM(RTRIM(name)))'), ['study leave', 'rehabilitation leave'])
            ->update([
                'max_days' => null,
                'updated_at' => now(),
            ]);
    }
};
