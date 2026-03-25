<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        if (!Schema::hasTable('tblNotifications') || Schema::hasColumn('tblNotifications', 'coc_application_id')) {
            return;
        }

        Schema::table('tblNotifications', function (Blueprint $table): void {
            $table->foreignId('coc_application_id')
                ->nullable()
                ->after('leave_application_id')
                ->constrained('tblCOCApplications')
                ->nullOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (!Schema::hasTable('tblNotifications') || !Schema::hasColumn('tblNotifications', 'coc_application_id')) {
            return;
        }

        Schema::table('tblNotifications', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('coc_application_id');
        });
    }
};
