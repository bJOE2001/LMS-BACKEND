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
            if (! Schema::hasColumn('tblDepartments', 'parent_department_id')) {
                $table->unsignedBigInteger('parent_department_id')->nullable()->after('name');
            }
            $table->foreign('parent_department_id')
                ->references('id')
                ->on('tblDepartments')
                ->onDelete('no action');
            $table->index('parent_department_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('tblDepartments', function (Blueprint $table) {
            $table->dropForeign(['parent_department_id']);
            $table->dropIndex(['parent_department_id']);
            $table->dropColumn('parent_department_id');
        });
    }
};
