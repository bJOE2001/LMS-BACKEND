<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        if (!Schema::hasTable('tblEmployees') || !Schema::hasColumn('tblEmployees', 'control_no_int')) {
            return;
        }

        if (DB::connection()->getDriverName() !== 'sqlsrv') {
            Schema::table('tblEmployees', function (Blueprint $table): void {
                $table->dropColumn('control_no_int');
            });

            return;
        }

        DB::unprepared(<<<'SQL'
DECLARE @dropFkSql NVARCHAR(MAX) = N'';
DECLARE @dropIdxSql NVARCHAR(MAX) = N'';
DECLARE @dropConstraintSql NVARCHAR(MAX) = N'';

SELECT @dropFkSql = @dropFkSql +
    N'ALTER TABLE ' + QUOTENAME(SCHEMA_NAME(parentTable.schema_id)) + N'.' + QUOTENAME(parentTable.name) +
    N' DROP CONSTRAINT ' + QUOTENAME(fk.name) + N';'
FROM sys.foreign_keys fk
INNER JOIN sys.foreign_key_columns fkc ON fk.object_id = fkc.constraint_object_id
INNER JOIN sys.tables referencedTable ON referencedTable.object_id = fkc.referenced_object_id
INNER JOIN sys.columns referencedColumn ON referencedColumn.object_id = fkc.referenced_object_id
    AND referencedColumn.column_id = fkc.referenced_column_id
INNER JOIN sys.tables parentTable ON parentTable.object_id = fkc.parent_object_id
WHERE referencedTable.name = 'tblEmployees'
  AND referencedColumn.name = 'control_no_int';

IF @dropFkSql <> N''
    EXEC sp_executesql @dropFkSql;

SELECT @dropConstraintSql = @dropConstraintSql +
    N'ALTER TABLE ' + QUOTENAME(SCHEMA_NAME(t.schema_id)) + N'.' + QUOTENAME(t.name) +
    N' DROP CONSTRAINT ' + QUOTENAME(cc.name) + N';'
FROM sys.check_constraints cc
INNER JOIN sys.tables t ON t.object_id = cc.parent_object_id
WHERE t.name = 'tblEmployees'
  AND cc.definition LIKE '%control_no_int%';

SELECT @dropConstraintSql = @dropConstraintSql +
    N'ALTER TABLE ' + QUOTENAME(SCHEMA_NAME(t.schema_id)) + N'.' + QUOTENAME(t.name) +
    N' DROP CONSTRAINT ' + QUOTENAME(dc.name) + N';'
FROM sys.default_constraints dc
INNER JOIN sys.columns c ON c.object_id = dc.parent_object_id AND c.column_id = dc.parent_column_id
INNER JOIN sys.tables t ON t.object_id = c.object_id
WHERE t.name = 'tblEmployees'
  AND c.name = 'control_no_int';

IF @dropConstraintSql <> N''
    EXEC sp_executesql @dropConstraintSql;

SELECT @dropIdxSql = @dropIdxSql +
    N'DROP INDEX ' + QUOTENAME(i.name) + N' ON ' +
    QUOTENAME(SCHEMA_NAME(t.schema_id)) + N'.' + QUOTENAME(t.name) + N';'
FROM sys.indexes i
INNER JOIN sys.index_columns ic ON i.object_id = ic.object_id AND i.index_id = ic.index_id
INNER JOIN sys.columns c ON c.object_id = ic.object_id AND c.column_id = ic.column_id
INNER JOIN sys.tables t ON t.object_id = i.object_id
WHERE t.name = 'tblEmployees'
  AND c.name = 'control_no_int'
  AND i.name IS NOT NULL
  AND i.is_primary_key = 0
  AND i.is_unique_constraint = 0;

IF @dropIdxSql <> N''
    EXEC sp_executesql @dropIdxSql;

IF COL_LENGTH('dbo.tblEmployees', 'control_no_int') IS NOT NULL
    ALTER TABLE dbo.tblEmployees DROP COLUMN control_no_int;
SQL);
    }

    public function down(): void
    {
        if (!Schema::hasTable('tblEmployees') || Schema::hasColumn('tblEmployees', 'control_no_int')) {
            return;
        }

        Schema::table('tblEmployees', function (Blueprint $table): void {
            $table->integer('control_no_int')->nullable();
        });
    }
};
