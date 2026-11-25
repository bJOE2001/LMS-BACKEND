<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const TABLE = 'tblEmployeeDepartmentAssignments';

    /**
     * Run the migrations.
     */
    public function up(): void
    {
        if (! Schema::hasTable(self::TABLE)) {
            return;
        }

        Schema::table(self::TABLE, function (Blueprint $table): void {
            if (
                Schema::hasColumn(self::TABLE, 'department_acronym')
                && ! Schema::hasColumn(self::TABLE, 'department')
            ) {
                $table->renameColumn('department_acronym', 'department');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (! Schema::hasTable(self::TABLE)) {
            return;
        }

        Schema::table(self::TABLE, function (Blueprint $table): void {
            if (
                Schema::hasColumn(self::TABLE, 'department')
                && ! Schema::hasColumn(self::TABLE, 'department_acronym')
            ) {
                $table->renameColumn('department', 'department_acronym');
            }
        });
    }
};
