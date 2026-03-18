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
            $table->unsignedBigInteger('reviewed_by_admin_id')->nullable()->after('status');
            $table->timestamp('admin_reviewed_at')->nullable()->after('reviewed_by_admin_id');

            $table->foreign('reviewed_by_admin_id')
                ->references('id')
                ->on('tblDepartmentAdmins')
                ->noActionOnDelete();

            $table->index(['reviewed_by_admin_id', 'admin_reviewed_at'], 'ix_tblcocapplications_admin_review');
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
            $table->dropIndex('ix_tblcocapplications_admin_review');
            $table->dropForeign(['reviewed_by_admin_id']);
            $table->dropColumn([
                'reviewed_by_admin_id',
                'admin_reviewed_at',
            ]);
        });
    }
};
