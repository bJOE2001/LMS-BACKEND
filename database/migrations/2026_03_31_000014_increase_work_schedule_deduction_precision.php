<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        if (Schema::hasTable('tblWorkScheduleSettings')) {
            DB::statement('ALTER TABLE tblWorkScheduleSettings ALTER COLUMN whole_day_leave_deduction DECIMAL(6,3) NOT NULL');
            DB::statement('ALTER TABLE tblWorkScheduleSettings ALTER COLUMN half_day_leave_deduction DECIMAL(6,3) NOT NULL');
        }

        if (Schema::hasTable('tblEmployeeWorkScheduleOverrides')) {
            DB::statement('ALTER TABLE tblEmployeeWorkScheduleOverrides ALTER COLUMN whole_day_leave_deduction DECIMAL(6,3) NOT NULL');
            DB::statement('ALTER TABLE tblEmployeeWorkScheduleOverrides ALTER COLUMN half_day_leave_deduction DECIMAL(6,3) NOT NULL');
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('tblWorkScheduleSettings')) {
            DB::statement('ALTER TABLE tblWorkScheduleSettings ALTER COLUMN whole_day_leave_deduction DECIMAL(5,2) NOT NULL');
            DB::statement('ALTER TABLE tblWorkScheduleSettings ALTER COLUMN half_day_leave_deduction DECIMAL(5,2) NOT NULL');
        }

        if (Schema::hasTable('tblEmployeeWorkScheduleOverrides')) {
            DB::statement('ALTER TABLE tblEmployeeWorkScheduleOverrides ALTER COLUMN whole_day_leave_deduction DECIMAL(5,2) NOT NULL');
            DB::statement('ALTER TABLE tblEmployeeWorkScheduleOverrides ALTER COLUMN half_day_leave_deduction DECIMAL(5,2) NOT NULL');
        }
    }
};
