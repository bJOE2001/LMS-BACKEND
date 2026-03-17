<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        if (!Schema::hasTable('tblCOCApplications')) {
            return;
        }

        Schema::table('tblCOCApplications', function (Blueprint $table): void {
            $table->unsignedBigInteger('reviewed_by_hr_id')->nullable()->after('status');
            $table->timestamp('reviewed_at')->nullable()->after('reviewed_by_hr_id');
            $table->unsignedBigInteger('cto_leave_type_id')->nullable()->after('reviewed_at');
            $table->decimal('cto_credited_days', 8, 2)->nullable()->after('cto_leave_type_id');
            $table->timestamp('cto_credited_at')->nullable()->after('cto_credited_days');

            $table->foreign('reviewed_by_hr_id')
                ->references('id')
                ->on('tblHRAccounts')
                ->noActionOnDelete();
            $table->foreign('cto_leave_type_id')
                ->references('id')
                ->on('tblLeaveTypes')
                ->noActionOnDelete();

            $table->index(['status', 'created_at'], 'ix_tblcocapplications_status_created_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (!Schema::hasTable('tblCOCApplications')) {
            return;
        }

        Schema::table('tblCOCApplications', function (Blueprint $table): void {
            $table->dropIndex('ix_tblcocapplications_status_created_at');
            $table->dropForeign(['reviewed_by_hr_id']);
            $table->dropForeign(['cto_leave_type_id']);
            $table->dropColumn([
                'reviewed_by_hr_id',
                'reviewed_at',
                'cto_leave_type_id',
                'cto_credited_days',
                'cto_credited_at',
            ]);
        });
    }
};
