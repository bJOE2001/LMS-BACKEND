<?php

namespace App\Console\Commands;

use App\Models\Employee;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Sync local employees from HRIS active view/table (read-only source).
 *
 * Notes:
 * - Reads employee rows from HRIS view/table (default: hr.vwActive).
 * - Upserts rows into tblEmployees by control_no.
 * - Sync scope is limited to allowed employment statuses only.
 * - Never writes to HRIS.
 */
class SyncHrisEmployees extends Command
{
    private const ALLOWED_STATUSES = [
        'REGULAR',
        'CO-TERMINOUS',
        'ELECTIVE',
        'CASUAL',
        'CONTRACTUAL',
    ];

    protected $signature = 'employees:sync
        {--connection=hr : HRIS database connection name}
        {--table=vwActive : HRIS table/view name}
        {--chunk=500 : Number of rows per upsert batch}
        {--dry-run : Preview changes without writing to LMS_DB}';

    protected $description = 'Sync tblEmployees from HRIS vwActive (allowed statuses only).';

    public function handle(): int
    {
        $connection = (string) $this->option('connection');
        $table = (string) $this->option('table');
        $chunkSize = max(1, (int) $this->option('chunk'));
        $dryRun = (bool) $this->option('dry-run');

        if (! $this->isSafeTableName($table)) {
            $this->error("Invalid --table value: {$table}");
            return self::FAILURE;
        }

        $columnMap = Employee::hrisColumnMap();
        foreach (array_keys($columnMap) as $column) {
            if (! $this->isSafeIdentifier($column)) {
                $this->error("Invalid HRIS source column name in map: {$column}");
                return self::FAILURE;
            }
        }

        $this->info("Reading HRIS employees from {$connection}.{$table}");
        $this->line('Allowed statuses: '.implode(', ', self::ALLOWED_STATUSES));

        try {
            $sourceRows = DB::connection($connection)
                ->table($table)
                ->select(array_keys($columnMap))
                ->get();
        } catch (Throwable $e) {
            $this->error('Failed to read HRIS source: '.$e->getMessage());
            return self::FAILURE;
        }

        if ($sourceRows->isEmpty()) {
            $this->warn('No rows found from HRIS source. Nothing changed.');
            return self::SUCCESS;
        }

        [$rowsToSync, $stats] = $this->normalizeRows($sourceRows);
        $statusCounts = $this->buildAllowedStatusCounts($rowsToSync);

        if ($rowsToSync === []) {
            $this->warn('No eligible employee rows after filtering. Nothing changed.');
            $this->line('Rows skipped due to disallowed status: '.$stats['skipped_status']);
            $this->line('Rows skipped due to missing control_no: '.$stats['skipped_control_no']);
            $this->line('Eligible rows by status:');
            foreach (self::ALLOWED_STATUSES as $status) {
                $this->line("  {$status}: ".($statusCounts[$status] ?? 0));
            }
            return self::SUCCESS;
        }

        $sampleRow = array_values($rowsToSync)[0] ?? [];
        $effectiveChunkSize = $this->resolveEffectiveChunkSize($chunkSize, $sampleRow);
        if ($effectiveChunkSize !== $chunkSize) {
            $this->warn(
                "Adjusted chunk size from {$chunkSize} to {$effectiveChunkSize} for SQL Server parameter limits."
            );
        }

        $existingLookup = [];
        foreach (array_chunk(array_keys($rowsToSync), 1000) as $controlNoChunk) {
            $existingControlNos = DB::table('tblEmployees')
                ->whereIn('control_no', $controlNoChunk)
                ->pluck('control_no')
                ->map(fn ($controlNo) => trim((string) $controlNo))
                ->filter(fn (string $controlNo): bool => $controlNo !== '')
                ->all();

            foreach ($existingControlNos as $controlNo) {
                $existingLookup[$controlNo] = true;
            }
        }

        $insertCount = 0;
        $updateCount = 0;
        foreach (array_keys($rowsToSync) as $controlNo) {
            if (isset($existingLookup[$controlNo])) {
                $updateCount++;
                continue;
            }

            $insertCount++;
        }

        $this->line('HRIS rows read: '.$sourceRows->count());
        $this->line('Eligible rows to sync: '.count($rowsToSync));
        $this->line('Expected inserts: '.$insertCount);
        $this->line('Expected updates: '.$updateCount);
        $this->line('Rows skipped due to disallowed status: '.$stats['skipped_status']);
        $this->line('Rows skipped due to missing control_no: '.$stats['skipped_control_no']);
        $this->line('Rows overwritten due to duplicate control_no: '.$stats['duplicate_control_no']);
        $this->line('Eligible rows by status:');
        foreach (self::ALLOWED_STATUSES as $status) {
            $this->line("  {$status}: ".($statusCounts[$status] ?? 0));
        }

        if ($dryRun) {
            $this->warn('Dry run enabled. No rows were written.');
            $previewRows = array_slice(array_values($rowsToSync), 0, 20);
            $this->table(
                ['ControlNo', 'Surname', 'Firstname', 'Middlename', 'BirthDate', 'Office', 'Status', 'Designation', 'RateMon'],
                array_map(static fn (array $row): array => [
                    $row['control_no'],
                    $row['surname'],
                    $row['firstname'],
                    $row['middlename'],
                    $row['birth_date'],
                    $row['office'],
                    $row['status'],
                    $row['designation'],
                    $row['rate_mon'],
                ], $previewRows)
            );

            if (count($rowsToSync) > count($previewRows)) {
                $this->line('Preview limited to first '.count($previewRows).' row(s).');
            }

            return self::SUCCESS;
        }

        $updateColumns = [
            'surname',
            'firstname',
            'middlename',
            'birth_date',
            'office',
            'status',
            'designation',
            'rate_mon',
            'updated_at',
        ];

        foreach (array_chunk(array_values($rowsToSync), $effectiveChunkSize) as $chunkRows) {
            DB::table('tblEmployees')->upsert(
                $chunkRows,
                ['control_no'],
                $updateColumns
            );
        }

        $this->info('Employee sync completed. tblEmployees is now updated from HRIS source.');

        return self::SUCCESS;
    }

    /**
     * @return array{0: array<string, array<string, mixed>>, 1: array<string, int>}
     */
    private function normalizeRows(\Illuminate\Support\Collection $sourceRows): array
    {
        $now = now();
        $normalizedRows = [];
        $stats = [
            'skipped_status' => 0,
            'skipped_control_no' => 0,
            'duplicate_control_no' => 0,
        ];

        foreach ($sourceRows as $row) {
            $controlNo = trim((string) $this->readRowValue($row, 'ControlNo'));
            if ($controlNo === '') {
                $stats['skipped_control_no']++;
                continue;
            }

            $status = strtoupper(trim((string) $this->readRowValue($row, 'Status')));
            if (! in_array($status, self::ALLOWED_STATUSES, true)) {
                $stats['skipped_status']++;
                continue;
            }

            if (isset($normalizedRows[$controlNo])) {
                $stats['duplicate_control_no']++;
            }

            $normalizedRows[$controlNo] = [
                'control_no' => $controlNo,
                'surname' => trim((string) $this->readRowValue($row, 'Surname')),
                'firstname' => trim((string) $this->readRowValue($row, 'Firstname')),
                'middlename' => $this->normalizeNullableString($this->readRowValue($row, 'Middlename')),
                'birth_date' => $this->normalizeNullableDate($this->readRowValue($row, 'BirthDate')),
                'office' => $this->normalizeNullableString($this->readRowValue($row, 'Office')),
                'status' => $status,
                'designation' => $this->normalizeNullableString($this->readRowValue($row, 'Designation')),
                'rate_mon' => $this->normalizeNullableDecimal($this->readRowValue($row, 'RateMon')),
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        return [$normalizedRows, $stats];
    }

    private function readRowValue(object $row, string $column): mixed
    {
        if (property_exists($row, $column)) {
            return $row->{$column};
        }

        foreach (get_object_vars($row) as $key => $value) {
            if (strcasecmp((string) $key, $column) === 0) {
                return $value;
            }
        }

        return null;
    }

    private function normalizeNullableString(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $text = trim((string) $value);

        return $text === '' ? null : $text;
    }

    private function normalizeNullableDecimal(mixed $value): ?float
    {
        if ($value === null) {
            return null;
        }

        $text = trim((string) $value);
        if ($text === '' || ! is_numeric($text)) {
            return null;
        }

        return round((float) $text, 2);
    }

    private function normalizeNullableDate(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        if ($value instanceof \DateTimeInterface) {
            return $value->format('Y-m-d');
        }

        $text = trim((string) $value);
        if ($text === '') {
            return null;
        }

        $date = date_create($text);

        return $date === false ? null : $date->format('Y-m-d');
    }

    private function resolveEffectiveChunkSize(int $requestedChunkSize, array $sampleRow): int
    {
        $requestedChunkSize = max(1, $requestedChunkSize);

        if (DB::connection()->getDriverName() !== 'sqlsrv' || $sampleRow === []) {
            return $requestedChunkSize;
        }

        $columnCount = max(1, count($sampleRow));
        $safeChunkSize = max(1, (int) floor(2000 / $columnCount));

        return min($requestedChunkSize, $safeChunkSize);
    }

    /**
     * @param  array<string, array<string, mixed>>  $rowsToSync
     * @return array<string, int>
     */
    private function buildAllowedStatusCounts(array $rowsToSync): array
    {
        $counts = array_fill_keys(self::ALLOWED_STATUSES, 0);

        foreach ($rowsToSync as $row) {
            $status = strtoupper(trim((string) ($row['status'] ?? '')));
            if (! isset($counts[$status])) {
                continue;
            }

            $counts[$status]++;
        }

        return $counts;
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
