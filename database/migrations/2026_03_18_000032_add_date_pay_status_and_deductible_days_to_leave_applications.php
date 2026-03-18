<?php

use App\Models\LeaveApplication;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tblLeaveApplications', function (Blueprint $table) {
            if (!Schema::hasColumn('tblLeaveApplications', 'selected_date_pay_status')) {
                $table->longText('selected_date_pay_status')->nullable()->after('selected_dates');
            }

            if (!Schema::hasColumn('tblLeaveApplications', 'selected_date_coverage')) {
                $table->longText('selected_date_coverage')->nullable()->after('selected_date_pay_status');
            }

            if (!Schema::hasColumn('tblLeaveApplications', 'deductible_days')) {
                $table->decimal('deductible_days', 5, 2)->nullable()->after('total_days');
            }
        });

        if (Schema::hasColumn('tblLeaveApplications', 'deductible_days')) {
            LeaveApplication::query()
                ->select(['id', 'is_monetization', 'pay_mode', 'total_days'])
                ->orderBy('id')
                ->chunkById(500, function ($rows): void {
                    foreach ($rows as $row) {
                        if (!$row instanceof LeaveApplication) {
                            continue;
                        }

                        $normalizedPayMode = strtoupper(trim((string) ($row->pay_mode ?? LeaveApplication::PAY_MODE_WITH_PAY)));
                        $totalDays = round((float) ($row->total_days ?? 0), 2);

                        if ((bool) $row->is_monetization) {
                            $deductibleDays = $totalDays;
                        } elseif ($normalizedPayMode === LeaveApplication::PAY_MODE_WITHOUT_PAY) {
                            $deductibleDays = 0.0;
                        } else {
                            $deductibleDays = $totalDays;
                        }

                        DB::table('tblLeaveApplications')
                            ->where('id', (int) $row->id)
                            ->update([
                                'deductible_days' => round(max($deductibleDays, 0.0), 2),
                            ]);
                    }
                });
        }
    }

    public function down(): void
    {
        Schema::table('tblLeaveApplications', function (Blueprint $table) {
            if (Schema::hasColumn('tblLeaveApplications', 'deductible_days')) {
                $table->dropColumn('deductible_days');
            }

            if (Schema::hasColumn('tblLeaveApplications', 'selected_date_coverage')) {
                $table->dropColumn('selected_date_coverage');
            }

            if (Schema::hasColumn('tblLeaveApplications', 'selected_date_pay_status')) {
                $table->dropColumn('selected_date_pay_status');
            }
        });
    }
};
