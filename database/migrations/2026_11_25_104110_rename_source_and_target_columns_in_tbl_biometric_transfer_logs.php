<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const CONNECTION = 'bio';

    private const TABLE = 'tblBiometricTransferLogs';

    /**
     * @var array<string, string>
     */
    private array $columnRenames = [
        'source_department_id' => 'from_department_id',
        'source_office_name' => 'from_office_name',
        'source_device_sn' => 'from_device_sn',
        'target_department_id' => 'to_department_id',
        'target_office_name' => 'to_office_name',
        'target_device_sn' => 'to_device_sn',
    ];

    /**
     * Run the migrations.
     */
    public function up(): void
    {
        $this->applyColumnRenames($this->columnRenames);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        $this->applyColumnRenames(array_flip($this->columnRenames));
    }

    /**
     * @param  array<string, string>  $renames
     */
    private function applyColumnRenames(array $renames): void
    {
        if (! Schema::connection(self::CONNECTION)->hasTable(self::TABLE)) {
            return;
        }

        foreach ($renames as $fromColumn => $toColumn) {
            if (
                Schema::connection(self::CONNECTION)->hasColumn(self::TABLE, $fromColumn)
                && ! Schema::connection(self::CONNECTION)->hasColumn(self::TABLE, $toColumn)
            ) {
                Schema::connection(self::CONNECTION)->table(self::TABLE, function (Blueprint $table) use ($fromColumn, $toColumn): void {
                    $table->renameColumn($fromColumn, $toColumn);
                });
            }
        }
    }
};
