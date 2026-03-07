<?php

namespace Database\Seeders;

use App\Models\Department;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

/**
 * LOCAL DEVELOPMENT ONLY — seeds LMS_DB.
 * Creates exactly 1 ADMIN and 1 HEAD user per department.
 * Does NOT connect to pmis2003 or BIOASD.
 */
class UserSeeder extends Seeder
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
                $slug = $this->departmentSlug($department->name);

                // Exactly 1 ADMIN per department
                User::firstOrCreate(
                    [
                        'email' => "{$slug}-admin@lms.test",
                    ],
                    [
                        'name'           => "{$department->name} Admin",
                        'password'       => Hash::make('password'),
                        'department_id'  => $department->id,
                        'role'           => User::ROLE_ADMIN,
                    ]
                );

                // Exactly 1 HEAD per department
                User::firstOrCreate(
                    [
                        'email' => "{$slug}-head@lms.test",
                    ],
                    [
                        'name'           => "{$department->name} Head",
                        'password'       => Hash::make('password'),
                        'department_id'  => $department->id,
                        'role'           => User::ROLE_HEAD,
                    ]
                );
            }
        });
    }

    private function departmentSlug(string $name): string
    {
        return str_replace(' ', '-', strtolower($name));
    }
}
