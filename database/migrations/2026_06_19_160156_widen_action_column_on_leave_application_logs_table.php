<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const TABLE = 'tblLeaveApplicationLogs';

    private const INDEX = 'IX_tblLeaveApplicationLogs_application_action_created';

    /**
     * Run the migrations.
     */
    public function up(): void
    {
        $this->dropWorkflowIndex();

        Schema::table(self::TABLE, function (Blueprint $table): void {
            $table->string('action', 64)->change();
        });

        $this->createWorkflowIndex();
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        $this->dropWorkflowIndex();

        Schema::table(self::TABLE, function (Blueprint $table): void {
            $table->string('action', 32)->change();
        });

        $this->createWorkflowIndex();
    }

    private function dropWorkflowIndex(): void
    {
        try {
            Schema::table(self::TABLE, function (Blueprint $table): void {
                $table->dropIndex(self::INDEX);
            });
        } catch (Throwable) {
            // Ignore when the performance index is not present in an environment.
        }
    }

    private function createWorkflowIndex(): void
    {
        try {
            Schema::table(self::TABLE, function (Blueprint $table): void {
                $table->index(
                    ['leave_application_id', 'action', 'created_at'],
                    self::INDEX
                );
            });
        } catch (Throwable) {
            // Ignore when the performance index already exists in an environment.
        }
    }
};
