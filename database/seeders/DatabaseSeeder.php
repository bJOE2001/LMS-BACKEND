<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed the application's database.
     * LOCAL DEVELOPMENT ONLY — uses LMS_DB. Order matters.
     *
     * Example: php artisan db:seed
     * Or:      php artisan db:seed --class=DepartmentSeeder
     */
    public function run(): void
    {
        if (! app()->environment('local')) {
            exit('Seeding allowed in local environment only.');
        }

        $this->call([
            RoleAndPermissionSeeder::class,
            HRAccountSeeder::class,
            DepartmentSeeder::class,
            DepartmentAdminSeeder::class,
            DepartmentHeadSeeder::class,
            EmployeeSeeder::class,
            LeaveTypeSeeder::class,
        ]);
    }
}
