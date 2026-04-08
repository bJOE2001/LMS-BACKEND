<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('tblCOCApplications')) {
            return;
        }

        Schema::table('tblCOCApplications', function (Blueprint $table): void {
            if (!Schema::hasColumn('tblCOCApplications', 'is_late_filed')) {
                $table->boolean('is_late_filed')->default(false)->after('status');
            }

            if (!Schema::hasColumn('tblCOCApplications', 'late_filing_status')) {
                $table->string('late_filing_status', 16)->nullable()->after('is_late_filed');
            }

            if (!Schema::hasColumn('tblCOCApplications', 'late_filing_reviewed_by_hr_id')) {
                $table->foreignId('late_filing_reviewed_by_hr_id')
                    ->nullable()
                    ->after('late_filing_status')
                    ->constrained('tblHRAccounts')
                    ->noActionOnDelete();
            }

            if (!Schema::hasColumn('tblCOCApplications', 'late_filing_reviewed_at')) {
                $table->timestamp('late_filing_reviewed_at')->nullable()->after('late_filing_reviewed_by_hr_id');
            }

            if (!Schema::hasColumn('tblCOCApplications', 'late_filing_review_remarks')) {
                $table->text('late_filing_review_remarks')->nullable()->after('late_filing_reviewed_at');
            }
        });

        Schema::table('tblCOCApplications', function (Blueprint $table): void {
            $table->index(['is_late_filed', 'late_filing_status', 'created_at'], 'ix_tblcocapplications_late_filing');
        });
    }

    public function down(): void
    {
        if (!Schema::hasTable('tblCOCApplications')) {
            return;
        }

        Schema::table('tblCOCApplications', function (Blueprint $table): void {
            try {
                $table->dropIndex('ix_tblcocapplications_late_filing');
            } catch (\Throwable) {
                // Ignore if the index was never created in this environment.
            }

            if (Schema::hasColumn('tblCOCApplications', 'late_filing_reviewed_by_hr_id')) {
                $table->dropConstrainedForeignId('late_filing_reviewed_by_hr_id');
            }
        });

        Schema::table('tblCOCApplications', function (Blueprint $table): void {
            foreach ([
                'late_filing_review_remarks',
                'late_filing_reviewed_at',
                'late_filing_status',
                'is_late_filed',
            ] as $column) {
                if (Schema::hasColumn('tblCOCApplications', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
