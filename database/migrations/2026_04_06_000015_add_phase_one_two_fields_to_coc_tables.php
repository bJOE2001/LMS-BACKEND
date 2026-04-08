<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tblCOCApplications', function (Blueprint $table): void {
            if (!Schema::hasColumn('tblCOCApplications', 'application_year')) {
                $table->unsignedSmallInteger('application_year')->nullable()->after('total_minutes');
            }

            if (!Schema::hasColumn('tblCOCApplications', 'application_month')) {
                $table->unsignedTinyInteger('application_month')->nullable()->after('application_year');
            }

            if (!Schema::hasColumn('tblCOCApplications', 'credited_hours')) {
                $table->decimal('credited_hours', 8, 2)->nullable()->after('application_month');
            }
        });

        if (
            Schema::hasTable('tblCOCApplications')
            && !Schema::hasIndex('tblCOCApplications', 'ix_tblcocapplications_employee_period')
        ) {
            Schema::table('tblCOCApplications', function (Blueprint $table): void {
                $table->index(
                    ['employee_control_no', 'application_year', 'application_month'],
                    'ix_tblcocapplications_employee_period'
                );
            });
        }

        Schema::table('tblCOCApplicationRows', function (Blueprint $table): void {
            if (!Schema::hasColumn('tblCOCApplicationRows', 'credit_category')) {
                $table->string('credit_category', 16)->nullable()->after('cumulative_minutes');
            }

            if (!Schema::hasColumn('tblCOCApplicationRows', 'credit_multiplier')) {
                $table->decimal('credit_multiplier', 4, 2)->nullable()->after('credit_category');
            }

            if (!Schema::hasColumn('tblCOCApplicationRows', 'creditable_minutes')) {
                $table->unsignedInteger('creditable_minutes')->nullable()->after('credit_multiplier');
            }

            if (!Schema::hasColumn('tblCOCApplicationRows', 'credited_hours')) {
                $table->decimal('credited_hours', 8, 2)->nullable()->after('creditable_minutes');
            }
        });
    }

    public function down(): void
    {
        if (
            Schema::hasTable('tblCOCApplications')
            && Schema::hasIndex('tblCOCApplications', 'ix_tblcocapplications_employee_period')
        ) {
            Schema::table('tblCOCApplications', function (Blueprint $table): void {
                $table->dropIndex('ix_tblcocapplications_employee_period');
            });
        }

        Schema::table('tblCOCApplications', function (Blueprint $table): void {
            if (Schema::hasColumn('tblCOCApplications', 'credited_hours')) {
                $table->dropColumn('credited_hours');
            }

            if (Schema::hasColumn('tblCOCApplications', 'application_month')) {
                $table->dropColumn('application_month');
            }

            if (Schema::hasColumn('tblCOCApplications', 'application_year')) {
                $table->dropColumn('application_year');
            }
        });

        Schema::table('tblCOCApplicationRows', function (Blueprint $table): void {
            foreach (['credited_hours', 'creditable_minutes', 'credit_multiplier', 'credit_category'] as $column) {
                if (Schema::hasColumn('tblCOCApplicationRows', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
