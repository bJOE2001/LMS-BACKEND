<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        if (!Schema::hasTable('tblLeaveApplications')) {
            return;
        }

        if (!Schema::hasColumn('tblLeaveApplications', 'medical_certificate_required')) {
            Schema::table('tblLeaveApplications', function (Blueprint $table): void {
                $table->boolean('medical_certificate_required')->default(false);
            });
        }

        if (!Schema::hasColumn('tblLeaveApplications', 'medical_certificate_submitted')) {
            Schema::table('tblLeaveApplications', function (Blueprint $table): void {
                $table->boolean('medical_certificate_submitted')->default(false);
            });
        }

        if (!Schema::hasColumn('tblLeaveApplications', 'medical_certificate_reference')) {
            Schema::table('tblLeaveApplications', function (Blueprint $table): void {
                $table->string('medical_certificate_reference', 500)->nullable();
            });
        }
    }

    public function down(): void
    {
        if (!Schema::hasTable('tblLeaveApplications')) {
            return;
        }

        if (Schema::hasColumn('tblLeaveApplications', 'medical_certificate_reference')) {
            Schema::table('tblLeaveApplications', function (Blueprint $table): void {
                $table->dropColumn('medical_certificate_reference');
            });
        }

        if (Schema::hasColumn('tblLeaveApplications', 'medical_certificate_submitted')) {
            Schema::table('tblLeaveApplications', function (Blueprint $table): void {
                $table->dropColumn('medical_certificate_submitted');
            });
        }

        if (Schema::hasColumn('tblLeaveApplications', 'medical_certificate_required')) {
            Schema::table('tblLeaveApplications', function (Blueprint $table): void {
                $table->dropColumn('medical_certificate_required');
            });
        }
    }
};
