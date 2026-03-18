<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('tblLeaveApplicationUpdateRequests')) {
            Schema::create('tblLeaveApplicationUpdateRequests', function (Blueprint $table) {
                $table->id();
                $table->foreignId('leave_application_id')
                    ->constrained('tblLeaveApplications')
                    ->cascadeOnDelete();
                $table->longText('requested_payload');
                $table->text('requested_reason')->nullable();
                $table->string('previous_status', 32)->nullable();
                $table->string('requested_by_control_no', 64)->nullable();
                $table->timestamp('requested_at')->nullable();
                $table->string('status', 32)->default('PENDING');
                $table->unsignedBigInteger('reviewed_by_hr_id')->nullable();
                $table->timestamp('reviewed_at')->nullable();
                $table->text('review_remarks')->nullable();
                $table->timestamps();

                $table->index(
                    ['leave_application_id', 'status'],
                    'IX_tblLeaveAppUpdateReq_leave_application_status'
                );
                $table->index('requested_by_control_no', 'IX_tblLeaveAppUpdateReq_requested_by');
            });
        }

        // Backfill legacy column-based pending update data into the new table.
        if (
            !Schema::hasTable('tblLeaveApplications')
            || !Schema::hasColumn('tblLeaveApplications', 'pending_update_payload')
        ) {
            return;
        }

        $legacyRows = DB::table('tblLeaveApplications')
            ->select([
                'id',
                'erms_control_no',
                'status',
                'pending_update_payload',
                'pending_update_reason',
                'pending_update_previous_status',
                'pending_update_requested_by',
                'pending_update_requested_at',
                'created_at',
                'updated_at',
            ])
            ->whereNotNull('pending_update_payload')
            ->get();

        foreach ($legacyRows as $row) {
            $alreadyExists = DB::table('tblLeaveApplicationUpdateRequests')
                ->where('leave_application_id', $row->id)
                ->exists();
            if ($alreadyExists) {
                continue;
            }

            $normalizedStatus = strtoupper(trim((string) ($row->status ?? '')));
            $requestStatus = $normalizedStatus === 'PENDING_HR'
                ? 'PENDING'
                : ($normalizedStatus === 'APPROVED' ? 'APPROVED' : 'REJECTED');

            DB::table('tblLeaveApplicationUpdateRequests')->insert([
                'leave_application_id' => (int) $row->id,
                'requested_payload' => $row->pending_update_payload,
                'requested_reason' => $row->pending_update_reason,
                'previous_status' => $row->pending_update_previous_status ?: 'APPROVED',
                'requested_by_control_no' => $row->pending_update_requested_by ?: $row->erms_control_no,
                'requested_at' => $row->pending_update_requested_at ?: $row->updated_at ?: $row->created_at,
                'status' => $requestStatus,
                'reviewed_by_hr_id' => null,
                'reviewed_at' => $requestStatus === 'PENDING' ? null : ($row->updated_at ?: $row->created_at),
                'review_remarks' => null,
                'created_at' => $row->created_at ?: now(),
                'updated_at' => $row->updated_at ?: now(),
            ]);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('tblLeaveApplicationUpdateRequests');
    }
};
