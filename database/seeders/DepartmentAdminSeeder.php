<?php

namespace Database\Seeders;

use App\Models\Department;
use App\Models\DepartmentAdmin;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

/**
 * LOCAL DEVELOPMENT ONLY — seeds LMS_DB.
 * Creates exactly 1 department admin per department.
 */
class DepartmentAdminSeeder extends Seeder
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
                $slug = $this->slug($department->name);
                DepartmentAdmin::firstOrCreate(
                    ['department_id' => $department->id],
                    [
                        'full_name' => "{$department->name} Admin",
                        'username'  => "{$slug}_admin",
                        'password'  => Hash::make('password'),
                    ]
                );
            }
        });
    }

    private function slug(string $name): string
    {
        return str_replace(' ', '_', strtolower($name));
    }
}
