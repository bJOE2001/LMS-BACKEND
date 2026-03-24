<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed the application's database.
     *
     * Example: php artisan db:seed
     * Or:      php artisan db:seed --class=DepartmentSeeder
     */
    public function run(): void
    {
        $this->call([
            DepartmentSeeder::class,
            HRAccountSeeder::class,
            LeaveTypeSeeder::class,
        ]);
    }
}
