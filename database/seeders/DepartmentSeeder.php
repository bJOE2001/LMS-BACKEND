<?php

namespace Database\Seeders;

use App\Models\Department;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * LOCAL DEVELOPMENT ONLY - syncs departments from tblEmployees.office.
 */
class DepartmentSeeder extends Seeder
{
    public function run(): void
    {
        if (! app()->environment('local')) {
            exit('Seeding allowed in local environment only.');
        }

        $officeNames = DB::table('tblEmployees')
            ->selectRaw('LTRIM(RTRIM(office)) as office')
            ->whereNotNull('office')
            ->whereRaw("LTRIM(RTRIM(office)) <> ''")
            ->distinct()
            ->orderBy('office')
            ->pluck('office')
            ->filter()
            ->values();

        if ($officeNames->isEmpty()) {
            $this->command?->warn('No employee office values found in tblEmployees. Departments were not changed.');
            return;
        }

        DB::transaction(function () use ($officeNames): void {
            $now = now();

            $existing = Department::query()->pluck('name');
            $toInsert = $officeNames->diff($existing)->values();

            if ($toInsert->isNotEmpty()) {
                DB::table('tblDepartments')->insert(
                    $toInsert->map(fn (string $name): array => [
                        'name' => $name,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ])->all()
                );
            }

            // Keep departments aligned with employee offices, but never break admin FKs.
            Department::query()
                ->whereNotIn('name', $officeNames->all())
                ->whereDoesntHave('admin')
                ->delete();
        });
    }
}
