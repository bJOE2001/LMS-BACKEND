<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    /**
     * Keep only the employee columns required for manual admin management.
     */
    public function up(): void
    {
        if (!Schema::hasTable('tblEmployees')) {
            return;
        }

        $this->dropPmisUniqueIndex();

        $dropColumns = [
            'pmis_no',
            'sex',
            'birth_date',
            'rate_day',
            'from_date',
            'to_date',
            'address',
            'tel_no',
        ];

        $existingColumns = array_values(array_filter(
            $dropColumns,
            static fn (string $column): bool => Schema::hasColumn('tblEmployees', $column)
        ));

        if ($existingColumns !== []) {
            Schema::table('tblEmployees', function (Blueprint $table) use ($existingColumns): void {
                $table->dropColumn($existingColumns);
            });
        }
    }

    /**
     * Restore removed legacy employee columns.
     */
    public function down(): void
    {
        if (!Schema::hasTable('tblEmployees')) {
            return;
        }

        $columnsToAdd = [
            'pmis_no' => !Schema::hasColumn('tblEmployees', 'pmis_no'),
            'sex' => !Schema::hasColumn('tblEmployees', 'sex'),
            'birth_date' => !Schema::hasColumn('tblEmployees', 'birth_date'),
            'rate_day' => !Schema::hasColumn('tblEmployees', 'rate_day'),
            'from_date' => !Schema::hasColumn('tblEmployees', 'from_date'),
            'to_date' => !Schema::hasColumn('tblEmployees', 'to_date'),
            'address' => !Schema::hasColumn('tblEmployees', 'address'),
            'tel_no' => !Schema::hasColumn('tblEmployees', 'tel_no'),
        ];

        if (in_array(true, $columnsToAdd, true)) {
            Schema::table('tblEmployees', function (Blueprint $table) use ($columnsToAdd): void {
                if ($columnsToAdd['pmis_no']) {
                    $table->string('pmis_no')->nullable();
                }
                if ($columnsToAdd['sex']) {
                    $table->string('sex')->nullable();
                }
                if ($columnsToAdd['birth_date']) {
                    $table->date('birth_date')->nullable();
                }
                if ($columnsToAdd['rate_day']) {
                    $table->decimal('rate_day', 10, 2)->nullable();
                }
                if ($columnsToAdd['from_date']) {
                    $table->date('from_date')->nullable();
                }
                if ($columnsToAdd['to_date']) {
                    $table->date('to_date')->nullable();
                }
                if ($columnsToAdd['address']) {
                    $table->string('address')->nullable();
                }
                if ($columnsToAdd['tel_no']) {
                    $table->string('tel_no')->nullable();
                }
            });
        }

        if (Schema::hasColumn('tblEmployees', 'pmis_no')) {
            $this->createPmisUniqueIndex();
        }
    }

    private function dropPmisUniqueIndex(): void
    {
        $driver = DB::connection()->getDriverName();

        try {
            if ($driver === 'mysql') {
                DB::statement('DROP INDEX tblEmployees_pmis_no_unique ON tblEmployees');
                return;
            }

            if ($driver === 'sqlite' || $driver === 'pgsql') {
                DB::statement('DROP INDEX IF EXISTS tblEmployees_pmis_no_unique');
                return;
            }

            DB::statement('DROP INDEX IF EXISTS tblEmployees_pmis_no_unique ON tblEmployees');
        } catch (\Throwable) {
            // Index may not exist in all environments.
        }
    }

    private function createPmisUniqueIndex(): void
    {
        $driver = DB::connection()->getDriverName();

        $this->dropPmisUniqueIndex();

        if ($driver === 'mysql') {
            DB::statement('CREATE UNIQUE INDEX tblEmployees_pmis_no_unique ON tblEmployees (pmis_no)');
            return;
        }

        if ($driver === 'sqlite' || $driver === 'pgsql') {
            DB::statement('CREATE UNIQUE INDEX tblEmployees_pmis_no_unique ON tblEmployees (pmis_no) WHERE pmis_no IS NOT NULL');
            return;
        }

        DB::statement('CREATE UNIQUE INDEX tblEmployees_pmis_no_unique ON tblEmployees (pmis_no) WHERE pmis_no IS NOT NULL');
    }
};
