<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    private const TABLE = 'tblCOCApplications';
    private const REVIEWED_FOREIGN = 'tblcocapplications_cmo_cbmo_reviewed_by_id_foreign';

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
            if (!Schema::hasColumn(self::TABLE, 'cmo_cbmo_reviewed_by_id')) {
                $table->foreignId('cmo_cbmo_reviewed_by_id')
                    ->nullable()
                    ->after('hr_received_by_id');
            }

            if (!Schema::hasColumn(self::TABLE, 'cmo_cbmo_reviewed_at')) {
                $table->timestamp('cmo_cbmo_reviewed_at')->nullable()->after('hr_received_at');
            }
        });

        if ($this->hasSqlServerForeignKey(self::TABLE, self::REVIEWED_FOREIGN)) {
            Schema::table(self::TABLE, function (Blueprint $table): void {
                $table->dropForeign(self::REVIEWED_FOREIGN);
            });
        }

        Schema::table(self::TABLE, function (Blueprint $table): void {
            if (Schema::hasColumn(self::TABLE, 'cmo_cbmo_reviewed_by_id')) {
                $table->foreign('cmo_cbmo_reviewed_by_id', self::REVIEWED_FOREIGN)
                    ->references('id')
                    ->on('tblHRAccounts')
                    ->noActionOnDelete();
            }
        });
    }

    public function down(): void
    {
        if ($this->hasSqlServerForeignKey(self::TABLE, self::REVIEWED_FOREIGN)) {
            Schema::table(self::TABLE, function (Blueprint $table): void {
                $table->dropForeign(self::REVIEWED_FOREIGN);
            });
        }

        Schema::table(self::TABLE, function (Blueprint $table): void {
            if (Schema::hasColumn(self::TABLE, 'cmo_cbmo_reviewed_by_id')) {
                $table->dropColumn('cmo_cbmo_reviewed_by_id');
            }

            if (Schema::hasColumn(self::TABLE, 'cmo_cbmo_reviewed_at')) {
                $table->dropColumn('cmo_cbmo_reviewed_at');
            }
        });
    }
};
