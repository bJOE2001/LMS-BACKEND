<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        if (!Schema::hasTable('tblLeaveApplications')) {
            return;
        }

        $this->renameColumnIfPresent('tblLeaveApplications', 'medical_certificate_required', 'attachment_required');
        $this->renameColumnIfPresent('tblLeaveApplications', 'medical_certificate_submitted', 'attachment_submitted');
        $this->renameColumnIfPresent('tblLeaveApplications', 'medical_certificate_reference', 'attachment_reference');

        Schema::table('tblLeaveApplications', function (Blueprint $table) {
            if (!Schema::hasColumn('tblLeaveApplications', 'attachment_required')) {
                $table->boolean('attachment_required')->default(false);
            }
            if (!Schema::hasColumn('tblLeaveApplications', 'attachment_submitted')) {
                $table->boolean('attachment_submitted')->default(false);
            }
            if (!Schema::hasColumn('tblLeaveApplications', 'attachment_reference')) {
                $table->string('attachment_reference', 500)->nullable();
            }
        });

        if (Schema::hasColumn('tblLeaveApplications', 'requires_documents')
            && Schema::hasColumn('tblLeaveApplications', 'attachment_required')) {
            DB::table('tblLeaveApplications')
                ->where('requires_documents', true)
                ->where('attachment_required', false)
                ->update(['attachment_required' => true]);
        }
    }

    public function down(): void
    {
        if (!Schema::hasTable('tblLeaveApplications')) {
            return;
        }

        $this->renameColumnIfPresent('tblLeaveApplications', 'attachment_required', 'medical_certificate_required');
        $this->renameColumnIfPresent('tblLeaveApplications', 'attachment_submitted', 'medical_certificate_submitted');
        $this->renameColumnIfPresent('tblLeaveApplications', 'attachment_reference', 'medical_certificate_reference');
    }

    private function renameColumnIfPresent(string $table, string $from, string $to): void
    {
        if (!Schema::hasColumn($table, $from) || Schema::hasColumn($table, $to)) {
            return;
        }

        if (DB::getDriverName() === 'sqlsrv') {
            DB::statement("EXEC sp_rename '{$table}.{$from}', '{$to}', 'COLUMN'");
            return;
        }

        Schema::table($table, function (Blueprint $tableBlueprint) use ($from, $to) {
            $tableBlueprint->renameColumn($from, $to);
        });
    }
};

