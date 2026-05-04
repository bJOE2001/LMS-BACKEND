<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        if (!Schema::hasTable('tblLeaveApplications')) {
            return;
        }

        if (Schema::hasColumn('tblLeaveApplications', 'deductible_days')) {
            DB::statement('ALTER TABLE [tblLeaveApplications] ALTER COLUMN [deductible_days] decimal(6,3) NULL');
        }

        if (Schema::hasColumn('tblLeaveApplications', 'linked_forced_leave_deducted_days')) {
            DB::statement('ALTER TABLE [tblLeaveApplications] ALTER COLUMN [linked_forced_leave_deducted_days] decimal(6,3) NULL');
        }

        if (Schema::hasColumn('tblLeaveApplications', 'linked_vacation_leave_deducted_days')) {
            DB::statement('ALTER TABLE [tblLeaveApplications] ALTER COLUMN [linked_vacation_leave_deducted_days] decimal(6,3) NULL');
        }
    }

    public function down(): void
    {
        if (!Schema::hasTable('tblLeaveApplications')) {
            return;
        }

        if (Schema::hasColumn('tblLeaveApplications', 'deductible_days')) {
            DB::statement('ALTER TABLE [tblLeaveApplications] ALTER COLUMN [deductible_days] decimal(5,2) NULL');
        }

        if (Schema::hasColumn('tblLeaveApplications', 'linked_forced_leave_deducted_days')) {
            DB::statement('ALTER TABLE [tblLeaveApplications] ALTER COLUMN [linked_forced_leave_deducted_days] decimal(5,2) NULL');
        }

        if (Schema::hasColumn('tblLeaveApplications', 'linked_vacation_leave_deducted_days')) {
            DB::statement('ALTER TABLE [tblLeaveApplications] ALTER COLUMN [linked_vacation_leave_deducted_days] decimal(5,2) NULL');
        }
    }
};
