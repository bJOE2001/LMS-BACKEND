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
        if (Schema::connection('bio')->hasTable('tblDailyTimeRecords')) {
            Schema::connection('bio')->table('tblDailyTimeRecords', function (Blueprint $table): void {
                if (! Schema::connection('bio')->hasColumn('tblDailyTimeRecords', 'workload_undertaken')) {
                    $table->string('workload_undertaken', 500)->nullable();
                }

                if (! Schema::connection('bio')->hasColumn('tblDailyTimeRecords', 'workload_quantity')) {
                    $table->string('workload_quantity', 100)->nullable();
                }
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (Schema::connection('bio')->hasTable('tblDailyTimeRecords')) {
            Schema::connection('bio')->table('tblDailyTimeRecords', function (Blueprint $table): void {
                $columns = [];

                if (Schema::connection('bio')->hasColumn('tblDailyTimeRecords', 'workload_undertaken')) {
                    $columns[] = 'workload_undertaken';
                }

                if (Schema::connection('bio')->hasColumn('tblDailyTimeRecords', 'workload_quantity')) {
                    $columns[] = 'workload_quantity';
                }

                if ($columns !== []) {
                    $table->dropColumn($columns);
                }
            });
        }
    }
};
