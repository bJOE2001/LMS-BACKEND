<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const TABLE = 'tblEmployeeDepartmentAssignments';

    /**
     * Run the migrations.
     */
    public function up(): void
    {
        if (
            ! Schema::hasTable(self::TABLE)
            || ! Schema::hasColumn(self::TABLE, 'department')
            || ! Schema::hasTable('tblDepartments')
        ) {
            return;
        }

        $departmentNames = DB::table('tblDepartments')->pluck('name', 'id');

        foreach ($departmentNames as $departmentId => $departmentName) {
            $trimmedName = trim((string) ($departmentName ?? ''));
            if ($trimmedName === '') {
                continue;
            }

            DB::table(self::TABLE)
                ->where('department_id', (int) $departmentId)
                ->update([
                    'department' => $trimmedName,
                ]);
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Backfill overwrote legacy office acronyms; original per-row values cannot be restored losslessly.
    }
};
