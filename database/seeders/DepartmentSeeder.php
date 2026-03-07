<?php

namespace Database\Seeders;

use App\Models\Department;
use Illuminate\Database\Seeder;

/**
 * LOCAL DEVELOPMENT ONLY — seeds LMS_DB.
 * Does NOT connect to pmis2003 or BIOASD.
 */
class DepartmentSeeder extends Seeder
{
    private const DEPARTMENTS = [
        'Human Resources',
        'Information Technology',
        'Accounting',
        'Engineering',
        'Administration',
    ];

    public function run(): void
    {
        if (! app()->environment('local')) {
            exit('Seeding allowed in local environment only.');
        }

        \DB::transaction(function (): void {
            foreach (self::DEPARTMENTS as $name) {
                Department::firstOrCreate(
                    ['name' => $name],
                    ['name' => $name]
                );
            }
        });
    }
}
