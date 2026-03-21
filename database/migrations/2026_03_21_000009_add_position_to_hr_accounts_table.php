<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        if (!Schema::hasColumn('tblHRAccounts', 'position')) {
            Schema::table('tblHRAccounts', function (Blueprint $table) {
                $table->string('position')->nullable()->after('full_name');
            });
        }

        DB::table('tblHRAccounts')
            ->where(function ($query) {
                $query->whereNull('position')
                    ->orWhere('position', '');
            })
            ->update(['position' => 'HR']);
    }

    public function down(): void
    {
        if (Schema::hasColumn('tblHRAccounts', 'position')) {
            Schema::table('tblHRAccounts', function (Blueprint $table) {
                $table->dropColumn('position');
            });
        }
    }
};
