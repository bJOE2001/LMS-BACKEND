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

        $timestamp = now();

        DB::table('tblLeaveTypes')
            ->whereRaw('LOWER(LTRIM(RTRIM(name))) = ?', ['study leave'])
            ->update([
                'max_days' => 180,
                'updated_at' => $timestamp,
            ]);

        DB::table('tblLeaveTypes')
            ->whereRaw('LOWER(LTRIM(RTRIM(name))) = ?', ['rehabilitation leave'])
            ->update([
                'max_days' => 180,
                'updated_at' => $timestamp,
            ]);
    }

    public function down(): void
    {
        if (!Schema::hasTable('tblLeaveTypes') || !Schema::hasColumn('tblLeaveTypes', 'max_days')) {
            return;
        }

        $timestamp = now();

        DB::table('tblLeaveTypes')
            ->whereRaw('LOWER(LTRIM(RTRIM(name))) = ?', ['study leave'])
            ->update([
                'max_days' => null,
                'updated_at' => $timestamp,
            ]);

        DB::table('tblLeaveTypes')
            ->whereRaw('LOWER(LTRIM(RTRIM(name))) = ?', ['rehabilitation leave'])
            ->update([
                'max_days' => null,
                'updated_at' => $timestamp,
            ]);
    }
};
