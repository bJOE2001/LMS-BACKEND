<?php

namespace App\Console\Commands;

use App\Models\BiometricDevice;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Command to freshly rebuild or truncate the dedicated biometric database (BIO_DB).
 */
class FreshBioDatabase extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'bio:fresh
                            {--truncate : Only wipe/truncate data records from biometric tables without dropping schemas}
                            {--seed : Seed a default active MB360 device after refreshing}
                            {--clean-ghosts : Drop leftover non-biometric tables that were mistakenly created in BIO_DB}
                            {--force : Force the operation without interactive confirmation}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Freshly rebuild or wipe the dedicated biometric database (BIO_DB)';

    /**
     * The official biometric tables managed by the bio connection.
     * Ordered from children to parents for safe deletion.
     *
     * @var list<string>
     */
    private const BIO_TABLES = [
        'tblDailyTimeRecords',
        'tblAttendanceRawLogs',
        'tblBiometricDeviceCommands',
        'tblBiometricEnrollments',
        'tblBiometricDevices',
    ];

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $connectionName = 'bio';

        try {
            $dbName = (string) DB::connection($connectionName)->getDatabaseName();
        } catch (\Throwable $e) {
            $this->error("Failed to connect to the [{$connectionName}] database: {$e->getMessage()}");

            return self::FAILURE;
        }

        $this->newLine();
        $this->line('<fg=cyan;options=bold>====================================================</>');
        $this->line('<fg=cyan;options=bold>     BIO_DB Database Management Console             </>');
        $this->line('<fg=cyan;options=bold>====================================================</>');
        $this->line("Connection:     <fg=yellow>{$connectionName}</>");
        $this->line("Target Database: <fg=green;options=bold>{$dbName}</>");
        $this->line('<fg=gray>Safety Note: LMS_DB (main system database) will NOT be touched.</>');
        $this->newLine();

        if (! $this->option('force')) {
            $actionWord = $this->option('truncate') ? 'wipe all records in' : 'drop and rebuild tables in';
            if (! $this->confirm("Are you sure you want to {$actionWord} [{$dbName}]?", true)) {
                $this->warn('Operation cancelled by user.');

                return self::SUCCESS;
            }
        }

        if ($this->option('truncate')) {
            return $this->handleTruncate($connectionName);
        }

        return $this->handleFreshRebuild($connectionName);
    }

    /**
     * Drops and rebuilds all biometric tables from migration definitions.
     */
    private function handleFreshRebuild(string $connectionName): int
    {
        $this->info('1. Dropping existing biometric tables...');
        foreach (self::BIO_TABLES as $table) {
            if (Schema::connection($connectionName)->hasTable($table)) {
                Schema::connection($connectionName)->dropIfExists($table);
                $this->line("  <fg=red>x</> Dropped table: {$table}");
            }
        }

        if ($this->option('clean-ghosts')) {
            $this->info('2. Cleaning orphan foreign keys & non-biometric tables from BIO_DB...');

            try {
                DB::connection($connectionName)->statement("
                    DECLARE @sql NVARCHAR(MAX) = N'';
                    SELECT @sql += N'ALTER TABLE ' + QUOTENAME(OBJECT_SCHEMA_NAME(parent_object_id))
                        + '.' + QUOTENAME(OBJECT_NAME(parent_object_id))
                        + ' DROP CONSTRAINT ' + QUOTENAME(name) + ';' + CHAR(13)
                    FROM sys.foreign_keys;
                    IF @sql <> N'' EXEC sp_executesql @sql;
                ");
            } catch (\Throwable $e) {
                // Ignore if driver or permissions differ
            }

            /** @var list<object{TABLE_NAME: string}> $existingTables */
            $existingTables = DB::connection($connectionName)
                ->select("SELECT TABLE_NAME FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_TYPE = 'BASE TABLE'");

            foreach ($existingTables as $row) {
                $tableName = (string) ($row->TABLE_NAME ?? '');
                if ($tableName !== '' && ! in_array($tableName, self::BIO_TABLES, true)) {
                    Schema::connection($connectionName)->dropIfExists($tableName);
                    $this->line("  <fg=magenta>x</> Dropped orphan table: {$tableName}");
                }
            }
        }

        $this->info('3. Rebuilding biometric tables from migrations...');

        $attendanceMigrationFile = database_path('migrations/2026_09_21_125026_create_bio_attendance_tables.php');
        if (file_exists($attendanceMigrationFile)) {
            $migration = require $attendanceMigrationFile;
            $migration->up();
            $this->line('  <fg=green>+</> Migrated: tblBiometricDevices, tblAttendanceRawLogs, tblDailyTimeRecords');
        }

        $commandMigrationFile = database_path('migrations/2026_09_21_135314_create_bio_commands_and_enrollments_tables.php');
        if (file_exists($commandMigrationFile)) {
            $migration = require $commandMigrationFile;
            $migration->up();
            $this->line('  <fg=green>+</> Migrated: tblBiometricEnrollments, tblBiometricDeviceCommands');
        }

        if ($this->option('seed')) {
            $this->info('4. Seeding default authorized biometric device...');
            BiometricDevice::query()->create([
                'device_name' => 'HR Enrollment MB360',
                'serial_number' => 'KMY2252000112',
                'ip_address' => '192.168.8.230',
                'port' => 4370,
                'comm_key' => '0',
                'communication_mode' => 'ADMS',
                'model_name' => 'MB360',
                'department_name' => 'Human Resource Management Office',
                'is_active' => true,
                'status' => 'OFFLINE',
            ]);
            $this->line('  <fg=green>+</> Seeded device: KMY2252000112 (HR Enrollment MB360)');
        }

        $this->newLine();
        $this->info('BIO_DB has been freshly rebuilt successfully!');
        $this->displayStatusTable($connectionName);

        return self::SUCCESS;
    }

    /**
     * Wipes data records from biometric tables while keeping schemas intact.
     */
    private function handleTruncate(string $connectionName): int
    {
        $this->info('Wiping data records from biometric tables...');

        foreach (self::BIO_TABLES as $table) {
            if (Schema::connection($connectionName)->hasTable($table)) {
                DB::connection($connectionName)->table($table)->delete();
                $this->line("  <fg=yellow>~</> Cleared records from: {$table}");
            }
        }

        $this->newLine();
        $this->info('All biometric records have been cleared successfully (schemas preserved).');
        $this->displayStatusTable($connectionName);

        return self::SUCCESS;
    }

    /**
     * Displays a formatted status table of biometric tables in BIO_DB.
     */
    private function displayStatusTable(string $connectionName): void
    {
        $rows = [];
        foreach (self::BIO_TABLES as $table) {
            $exists = Schema::connection($connectionName)->hasTable($table);
            $count = $exists ? DB::connection($connectionName)->table($table)->count() : 0;
            $rows[] = [
                $table,
                $exists ? 'Ready' : 'Missing',
                (string) $count,
            ];
        }

        $this->table(['Table Name', 'Status', 'Record Count'], $rows);
    }
}
