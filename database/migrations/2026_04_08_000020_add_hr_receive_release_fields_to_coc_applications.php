<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    private const TABLE = 'tblCOCApplications';
    private const RECEIVED_FOREIGN = 'tblcocapplications_hr_received_by_id_foreign';
    private const RELEASED_FOREIGN = 'tblcocapplications_hr_released_by_id_foreign';

    private function hasSqlServerForeignKey(string $tableName, string $foreignName): bool
    {
        if (DB::connection()->getDriverName() !== 'sqlsrv') {
            return false;
        }

        $rows = DB::select(
            <<<'SQL'
            SELECT TOP 1 1 AS [present]
            FROM sys.foreign_keys fk
            INNER JOIN sys.tables t ON fk.parent_object_id = t.object_id
            WHERE fk.name = ? AND t.name = ?
            SQL,
            [$foreignName, $tableName],
        );

        return count($rows) > 0;
    }

    public function up(): void
    {
        Schema::table(self::TABLE, function (Blueprint $table): void {
            if (!Schema::hasColumn(self::TABLE, 'hr_received_by_id')) {
                $table->foreignId('hr_received_by_id')
                    ->nullable()
                    ->after('reviewed_by_hr_id');
            }

            if (!Schema::hasColumn(self::TABLE, 'hr_received_at')) {
                $table->timestamp('hr_received_at')->nullable()->after('reviewed_at');
            }

            if (!Schema::hasColumn(self::TABLE, 'hr_released_by_id')) {
                $table->foreignId('hr_released_by_id')
                    ->nullable()
                    ->after('hr_received_by_id');
            }

            if (!Schema::hasColumn(self::TABLE, 'hr_released_at')) {
                $table->timestamp('hr_released_at')->nullable()->after('hr_received_at');
            }
        });

        if ($this->hasSqlServerForeignKey(self::TABLE, self::RECEIVED_FOREIGN)) {
            Schema::table(self::TABLE, function (Blueprint $table): void {
                $table->dropForeign(self::RECEIVED_FOREIGN);
            });
        }

        if ($this->hasSqlServerForeignKey(self::TABLE, self::RELEASED_FOREIGN)) {
            Schema::table(self::TABLE, function (Blueprint $table): void {
                $table->dropForeign(self::RELEASED_FOREIGN);
            });
        }

        Schema::table(self::TABLE, function (Blueprint $table): void {
            if (Schema::hasColumn(self::TABLE, 'hr_received_by_id')) {
                $table->foreign('hr_received_by_id', self::RECEIVED_FOREIGN)
                    ->references('id')
                    ->on('tblHRAccounts')
                    ->noActionOnDelete();
            }

            if (Schema::hasColumn(self::TABLE, 'hr_released_by_id')) {
                $table->foreign('hr_released_by_id', self::RELEASED_FOREIGN)
                    ->references('id')
                    ->on('tblHRAccounts')
                    ->noActionOnDelete();
            }
        });
    }

    public function down(): void
    {
        if ($this->hasSqlServerForeignKey(self::TABLE, self::RELEASED_FOREIGN)) {
            Schema::table(self::TABLE, function (Blueprint $table): void {
                $table->dropForeign(self::RELEASED_FOREIGN);
            });
        }

        if ($this->hasSqlServerForeignKey(self::TABLE, self::RECEIVED_FOREIGN)) {
            Schema::table(self::TABLE, function (Blueprint $table): void {
                $table->dropForeign(self::RECEIVED_FOREIGN);
            });
        }

        Schema::table(self::TABLE, function (Blueprint $table): void {
            if (Schema::hasColumn(self::TABLE, 'hr_released_by_id')) {
                $table->dropColumn('hr_released_by_id');
            }
            if (Schema::hasColumn(self::TABLE, 'hr_received_by_id')) {
                $table->dropColumn('hr_received_by_id');
            }
        });

        Schema::table(self::TABLE, function (Blueprint $table): void {
            if (Schema::hasColumn(self::TABLE, 'hr_released_at')) {
                $table->dropColumn('hr_released_at');
            }
            if (Schema::hasColumn(self::TABLE, 'hr_received_at')) {
                $table->dropColumn('hr_received_at');
            }
        });
    }
};
