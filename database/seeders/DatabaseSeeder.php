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
     * Non-local execution is blocked unless:
     *   APP_ALLOW_NON_LOCAL_SEEDING=true
     *
     * Example: php artisan db:seed
     * Or:      php artisan db:seed --class=DepartmentSeeder
     */
    public function run(): void
    {
        $isLocal = app()->environment('local');
        $allowNonLocal = filter_var(
            (string) env('APP_ALLOW_NON_LOCAL_SEEDING', 'false'),
            FILTER_VALIDATE_BOOLEAN
        );

        if (! $isLocal && ! $allowNonLocal) {
            throw new \RuntimeException(
                'DatabaseSeeder is blocked outside local unless APP_ALLOW_NON_LOCAL_SEEDING=true.'
            );
        }

        $this->call([
            HRAccountSeeder::class,
            LeaveTypeSeeder::class,
        ]);
    }
}

