<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const TABLE = 'tblEmployeeDepartmentAssignments';
    private const TEMP_TABLE = 'tblEmployeeDepartmentAssignments__tmp_reorder';

    /**
     * New preferred order.
     *
     * @var array<int, string>
     */
    private array $upOrder = [
        'id',
        'employee_control_no',
        'surname',
        'firstname',
        'middlename',
        'department_acronym',
        'department_id',
        'assigned_by_department_admin_id',
        'assigned_at',
        'created_at',
        'updated_at',
    ];

    /**
     * Previous order (for rollback).
     *
     * @var array<int, string>
     */
    private array $downOrder = [
        'id',
        'employee_control_no',
        'department_id',
        'assigned_by_department_admin_id',
        'assigned_at',
        'created_at',
        'updated_at',
        'surname',
        'firstname',
        'middlename',
        'department_acronym',
    ];

    public function up(): void
    {
        $this->reorderTo($this->upOrder);
    }

    public function down(): void
    {
        $this->reorderTo($this->downOrder);
    }

    private function reorderTo(array $targetOrder): void
    {
        if (DB::getDriverName() !== 'sqlsrv') {
            return;
        }

        if (!Schema::hasTable(self::TABLE)) {
            return;
        }

        $this->ensureIdentityColumnsExist();

        if ($this->hasColumnOrder($targetOrder)) {
            return;
        }

        $this->assertKnownColumnSet();

        if (Schema::hasTable(self::TEMP_TABLE)) {
            Schema::drop(self::TEMP_TABLE);
        }

        Schema::create(self::TEMP_TABLE, function (Blueprint $table) use ($targetOrder): void {
            foreach ($targetOrder as $column) {
                match ($column) {
                    'id' => $table->id(),
                    'employee_control_no' => $table->string('employee_control_no')->unique(),
                    'surname' => $table->string('surname')->nullable(),
                    'firstname' => $table->string('firstname')->nullable(),
                    'middlename' => $table->string('middlename')->nullable(),
                    'department_acronym' => $table->string('department_acronym')->nullable(),
                    'department_id' => $table->foreignId('department_id')
                        ->constrained('tblDepartments')
                        ->cascadeOnDelete(),
                    'assigned_by_department_admin_id' => $table->unsignedBigInteger('assigned_by_department_admin_id')->nullable(),
                    'assigned_at' => $table->timestamp('assigned_at')->nullable(),
                    'created_at' => $table->timestamp('created_at')->nullable(),
                    'updated_at' => $table->timestamp('updated_at')->nullable(),
                    default => null,
                };
            }

            $table->index('department_id');
            $table->index('assigned_by_department_admin_id');
        });

        DB::unprepared("
            SET IDENTITY_INSERT [".self::TEMP_TABLE."] ON;
            INSERT INTO [".self::TEMP_TABLE."] (
                [id],
                [employee_control_no],
                [surname],
                [firstname],
                [middlename],
                [department_acronym],
                [department_id],
                [assigned_by_department_admin_id],
                [assigned_at],
                [created_at],
                [updated_at]
            )
            SELECT
                [id],
                [employee_control_no],
                [surname],
                [firstname],
                [middlename],
                [department_acronym],
                [department_id],
                [assigned_by_department_admin_id],
                [assigned_at],
                [created_at],
                [updated_at]
            FROM [".self::TABLE."];
            SET IDENTITY_INSERT [".self::TEMP_TABLE."] OFF;
        ");

        Schema::drop(self::TABLE);
        Schema::rename(self::TEMP_TABLE, self::TABLE);
    }

    private function ensureIdentityColumnsExist(): void
    {
        if (
            !Schema::hasColumn(self::TABLE, 'surname')
            || !Schema::hasColumn(self::TABLE, 'firstname')
            || !Schema::hasColumn(self::TABLE, 'middlename')
            || !Schema::hasColumn(self::TABLE, 'department_acronym')
        ) {
            Schema::table(self::TABLE, function (Blueprint $table): void {
                if (!Schema::hasColumn(self::TABLE, 'surname')) {
                    $table->string('surname')->nullable();
                }

                if (!Schema::hasColumn(self::TABLE, 'firstname')) {
                    $table->string('firstname')->nullable();
                }

                if (!Schema::hasColumn(self::TABLE, 'middlename')) {
                    $table->string('middlename')->nullable();
                }

                if (!Schema::hasColumn(self::TABLE, 'department_acronym')) {
                    $table->string('department_acronym')->nullable();
                }
            });
        }
    }

    private function hasColumnOrder(array $expectedOrder): bool
    {
        $actual = DB::table('INFORMATION_SCHEMA.COLUMNS')
            ->where('TABLE_NAME', self::TABLE)
            ->orderBy('ORDINAL_POSITION')
            ->pluck('COLUMN_NAME')
            ->map(static fn (mixed $name): string => strtolower(trim((string) $name)))
            ->values()
            ->all();

        $expected = array_map(static fn (string $name): string => strtolower($name), $expectedOrder);

        return $actual === $expected;
    }

    private function assertKnownColumnSet(): void
    {
        $expected = collect([
            'id',
            'employee_control_no',
            'department_id',
            'assigned_by_department_admin_id',
            'assigned_at',
            'created_at',
            'updated_at',
            'surname',
            'firstname',
            'middlename',
            'department_acronym',
        ])->sort()->values()->all();

        $actual = collect(Schema::getColumnListing(self::TABLE))
            ->map(static fn (string $name): string => strtolower(trim($name)))
            ->sort()
            ->values()
            ->all();

        if ($actual !== $expected) {
            throw new RuntimeException(
                'Unexpected columns on '.self::TABLE.'. Reorder migration aborted to avoid data loss.'
            );
        }
    }
};
