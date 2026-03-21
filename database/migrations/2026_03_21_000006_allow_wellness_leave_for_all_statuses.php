<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('tblLeaveTypes')
            ->whereRaw('LOWER(LTRIM(RTRIM(name))) = ?', ['wellness leave'])
            ->update([
                'allowed_status' => null,
            ]);
    }

    public function down(): void
    {
        DB::table('tblLeaveTypes')
            ->whereRaw('LOWER(LTRIM(RTRIM(name))) = ?', ['wellness leave'])
            ->update([
                'allowed_status' => json_encode(['contractual'], JSON_THROW_ON_ERROR),
            ]);
    }
};

