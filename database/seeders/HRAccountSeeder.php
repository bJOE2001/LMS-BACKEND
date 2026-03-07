<?php

namespace Database\Seeders;

use App\Models\HRAccount;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

/**
 * LOCAL DEVELOPMENT ONLY — seeds LMS_DB.
 */
class HRAccountSeeder extends Seeder
{
    public function run(): void
    {
        if (! app()->environment('local')) {
            exit('Seeding allowed in local environment only.');
        }

        \DB::transaction(function (): void {
            HRAccount::firstOrCreate(
                ['username' => 'hr_admin'],
                [
                    'full_name' => 'HR Admin',
                    'password'  => Hash::make('password123'),
                ]
            );
        });
    }
}
