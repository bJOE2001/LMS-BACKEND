<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tblCOCApplications', function (Blueprint $table): void {
            if (!Schema::hasColumn('tblCOCApplications', 'certificate_number')) {
                $table->string('certificate_number')->nullable()->after('credited_hours');
            }

            if (!Schema::hasColumn('tblCOCApplications', 'certificate_issued_at')) {
                $table->date('certificate_issued_at')->nullable()->after('certificate_number');
            }
        });
    }

    public function down(): void
    {
        Schema::table('tblCOCApplications', function (Blueprint $table): void {
            if (Schema::hasColumn('tblCOCApplications', 'certificate_issued_at')) {
                $table->dropColumn('certificate_issued_at');
            }

            if (Schema::hasColumn('tblCOCApplications', 'certificate_number')) {
                $table->dropColumn('certificate_number');
            }
        });
    }
};
