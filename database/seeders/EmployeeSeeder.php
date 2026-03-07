<?php

namespace Database\Seeders;

use App\Models\Department;
use App\Models\Employee;
use Illuminate\Database\Seeder;

/**
 * LOCAL DEVELOPMENT ONLY — seeds LMS_DB.
 * Creates 10 employees per department.
 * Does NOT connect to pmis2003 or BIOASD.
 */
class EmployeeSeeder extends Seeder
{
    private const EMPLOYEES_PER_DEPARTMENT = 10;

    public function run(): void
    {
        if (! app()->environment('local')) {
            exit('Seeding allowed in local environment only.');
        }

        $departments = Department::orderBy('id')->get();
        if ($departments->isEmpty()) {
            $this->command->warn('No departments found. Run DepartmentSeeder first.');

            return;
        }

        \DB::transaction(function () use ($departments): void {
            foreach ($departments as $department) {
                Employee::factory()
                    ->count(self::EMPLOYEES_PER_DEPARTMENT)
                    ->forDepartment($department)
                    ->create();
            }
        });
    }
}
