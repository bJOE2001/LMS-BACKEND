<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

/**
 * Ensures the main HR login (hr_admin / password123) exists in the users table
 * so the web login (Sanctum) works. HRAccountSeeder seeds hr_accounts; this seeds users.
 */
class DefaultLoginUserSeeder extends Seeder
{
    public function run(): void
    {
        if (! app()->environment('local')) {
            exit('Seeding allowed in local environment only.');
        }

        \DB::transaction(function (): void {
            $user = User::firstOrCreate(
                ['username' => 'hr_admin'],
                [
                    'name'     => 'HR Admin',
                    'email'    => 'hr_admin@lms.local',
                    'password' => Hash::make('password123'),
                ]
            );
            if (! $user->hasRole('hr')) {
                $user->assignRole('hr');
            }
        });
    }
}
