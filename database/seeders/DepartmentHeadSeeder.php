<?php

namespace Database\Seeders;

use App\Models\Department;
use App\Models\DepartmentHead;
use Illuminate\Database\Seeder;

/**
 * LOCAL DEVELOPMENT ONLY — seeds LMS_DB.
 * Creates exactly 1 department head per department (no login credentials).
 */
class DepartmentHeadSeeder extends Seeder
{
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
                DepartmentHead::firstOrCreate(
                    ['department_id' => $department->id],
                    [
                        'full_name' => "{$department->name} Head",
                        'position'  => 'Department Head',
                    ]
                );
            }
        });
    }
}
