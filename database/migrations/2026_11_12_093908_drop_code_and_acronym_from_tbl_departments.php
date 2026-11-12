<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('tblDepartments', function (Blueprint $table) {
            $columnsToDrop = [];
            if (Schema::hasColumn('tblDepartments', 'code')) {
                $columnsToDrop[] = 'code';
            }
            if (Schema::hasColumn('tblDepartments', 'acronym')) {
                $columnsToDrop[] = 'acronym';
            }

            if (! empty($columnsToDrop)) {
                $table->dropColumn($columnsToDrop);
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('tblDepartments', function (Blueprint $table) {
            if (! Schema::hasColumn('tblDepartments', 'code')) {
                $table->string('code')->nullable();
            }
            if (! Schema::hasColumn('tblDepartments', 'acronym')) {
                $table->string('acronym')->nullable();
            }
        });
    }
};
