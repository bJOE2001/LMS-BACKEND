<?php

namespace App\Console\Commands;

use App\Models\Department;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Sync local departments from HRIS offices (read-only source).
 *
 * Notes:
 * - Reads distinct office values from HRIS view/table (default: hr.vwActive, Office).
 * - Inserts only missing department names into tblDepartments.
 * - Never writes to HRIS.
 */
class SyncHrisDepartments extends Command
{
    protected $signature = 'departments:sync
        {--connection=hr : HRIS database connection name}
        {--table=vwActive : HRIS table/view name}
        {--column=Office : HRIS office/department column}
        {--dry-run : Preview changes without writing to LMS_DB}';

    protected $description = 'Sync tblDepartments from distinct HRIS office values (read-only HRIS source).';

    public function handle(): int
    {
        $connection = (string) $this->option('connection');
        $table = (string) $this->option('table');
        $column = (string) $this->option('column');
        $dryRun = (bool) $this->option('dry-run');

        if (! $this->isSafeIdentifier($column)) {
            $this->error("Invalid --column value: {$column}");
            return self::FAILURE;
        }

        if (! $this->isSafeTableName($table)) {
            $this->error("Invalid --table value: {$table}");
            return self::FAILURE;
        }

        $this->info("Reading HRIS offices from {$connection}.{$table}.{$column}");

        try {
            $officeNames = DB::connection($connection)
                ->table($table)
                ->whereNotNull($column)
                ->distinct()
                ->orderBy($column)
                ->pluck($column)
                ->map(fn ($name) => trim((string) $name))
                ->filter(fn (string $name) => $name !== '')
                ->values();
        } catch (Throwable $e) {
            $this->error('Failed to read HRIS source: '.$e->getMessage());
            return self::FAILURE;
        }

        if ($officeNames->isEmpty()) {
            $this->warn('No office values found from HRIS source. Nothing changed.');
            return self::SUCCESS;
        }

        $existing = Department::query()
            ->pluck('name')
            ->map(fn ($name) => trim((string) $name))
            ->filter(fn (string $name) => $name !== '')
            ->values();

        $existingLookup = $existing
            ->mapWithKeys(fn (string $name): array => [strtolower($name) => true]);

        $toInsert = $officeNames
            ->filter(fn (string $name): bool => ! $existingLookup->has(strtolower($name)))
            ->unique(fn (string $name): string => strtolower($name))
            ->values();

        $this->line('HRIS offices found: '.$officeNames->count());
        $this->line('Existing LMS departments: '.$existing->count());
        $this->line('Missing departments to insert: '.$toInsert->count());

        if ($toInsert->isEmpty()) {
            $this->info('tblDepartments is already up to date.');
            return self::SUCCESS;
        }

        if ($dryRun) {
            $this->warn('Dry run enabled. No rows were written.');
            $this->table(['Department Name'], $toInsert->map(fn (string $name): array => [$name])->all());
            return self::SUCCESS;
        }

        $now = now();
        $rows = $toInsert->map(fn (string $name): array => [
            'name' => $name,
            'created_at' => $now,
            'updated_at' => $now,
        ])->all();

        DB::table('tblDepartments')->insert($rows);

        $this->info('Inserted '.$toInsert->count().' department(s) into tblDepartments.');

        return self::SUCCESS;
    }

    private function isSafeIdentifier(string $value): bool
    {
        return (bool) preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $value);
    }

    private function isSafeTableName(string $value): bool
    {
        return (bool) preg_match('/^[A-Za-z_][A-Za-z0-9_\.]*$/', $value);
    }
}
