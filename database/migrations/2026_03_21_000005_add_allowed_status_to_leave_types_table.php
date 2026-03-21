<?php

use App\Models\LeaveType;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasColumn('tblLeaveTypes', 'allowed_status')) {
            Schema::table('tblLeaveTypes', function (Blueprint $table): void {
                $table->json('allowed_status')->nullable()->after('requires_documents');
            });
        }

        $generalStatuses = [
            LeaveType::EMPLOYMENT_STATUS_REGULAR,
            LeaveType::EMPLOYMENT_STATUS_ELECTIVE,
            LeaveType::EMPLOYMENT_STATUS_CO_TERMINOUS,
            LeaveType::EMPLOYMENT_STATUS_CASUAL,
        ];

        DB::table('tblLeaveTypes')
            ->whereNull('allowed_status')
            ->update([
                'allowed_status' => json_encode($generalStatuses, JSON_THROW_ON_ERROR),
            ]);

        DB::table('tblLeaveTypes')
            ->whereRaw('LOWER(LTRIM(RTRIM(name))) = ?', ['wellness leave'])
            ->update([
                'allowed_status' => null,
            ]);
    }

    public function down(): void
    {
        if (Schema::hasColumn('tblLeaveTypes', 'allowed_status')) {
            Schema::table('tblLeaveTypes', function (Blueprint $table): void {
                $table->dropColumn('allowed_status');
            });
        }
    }
};

