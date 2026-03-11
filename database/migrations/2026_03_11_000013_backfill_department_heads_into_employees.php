<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        if (!Schema::hasTable('tblDepartmentHeads') || !Schema::hasTable('tblEmployees')) {
            return;
        }

        $rows = DB::table('tblDepartmentHeads')
            ->select([
                'control_no',
                'surname',
                'firstname',
                'middlename',
                'office',
                'status',
                'designation',
                'rate_mon',
            ])
            ->whereNotNull('control_no')
            ->orderBy('id')
            ->get()
            ->map(function ($row): array {
                return [
                    'control_no' => trim((string) ($row->control_no ?? '')),
                    'surname' => trim((string) ($row->surname ?? '')),
                    'firstname' => trim((string) ($row->firstname ?? '')),
                    'middlename' => ($row->middlename !== null && trim((string) $row->middlename) !== '')
                        ? trim((string) $row->middlename)
                        : null,
                    'office' => trim((string) ($row->office ?? '')),
                    'status' => strtoupper(trim((string) ($row->status ?? ''))),
                    'designation' => ($row->designation !== null && trim((string) $row->designation) !== '')
                        ? trim((string) $row->designation)
                        : null,
                    'rate_mon' => $row->rate_mon !== null ? round((float) $row->rate_mon, 2) : null,
                    'created_at' => now(),
                    'updated_at' => now(),
                ];
            })
            ->filter(fn(array $row): bool => $row['control_no'] !== '')
            ->values()
            ->all();

        foreach (array_chunk($rows, 500) as $chunk) {
            DB::table('tblEmployees')->upsert(
                $chunk,
                ['control_no'],
                ['surname', 'firstname', 'middlename', 'office', 'status', 'designation', 'rate_mon', 'updated_at']
            );
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Intentionally left blank. Employee records may already have leave balances/applications.
    }
};
