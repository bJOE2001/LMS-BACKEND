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
        Schema::table('tblDepartmentHeads', function (Blueprint $table): void {
            if (Schema::hasColumn('tblDepartmentHeads', 'position')) {
                $table->dropColumn('position');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('tblDepartmentHeads', function (Blueprint $table): void {
            if (! Schema::hasColumn('tblDepartmentHeads', 'position')) {
                $table->string('position')->nullable();
            }
        });
    }
};
