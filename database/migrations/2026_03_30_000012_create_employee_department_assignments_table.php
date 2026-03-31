<?php

use Illuminate\Database\Migrations\Migration;

return new class extends Migration {
    public function up(): void
    {
        // No-op: tblEmployeeDepartmentAssignments is already created by
        // 2026_01_01_000001_create_lms_core_tables.php.
    }

    public function down(): void
    {
        // No-op for the same reason as up().
    }
};
