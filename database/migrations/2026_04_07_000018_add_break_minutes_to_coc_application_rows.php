<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tblCOCApplicationRows', function (Blueprint $table): void {
            if (!Schema::hasColumn('tblCOCApplicationRows', 'break_minutes')) {
                $table->unsignedInteger('break_minutes')->default(0)->after('minutes');
            }
        });
    }

    public function down(): void
    {
        Schema::table('tblCOCApplicationRows', function (Blueprint $table): void {
            if (Schema::hasColumn('tblCOCApplicationRows', 'break_minutes')) {
                $table->dropColumn('break_minutes');
            }
        });
    }
};
