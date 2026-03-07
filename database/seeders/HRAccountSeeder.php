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
            $accounts = [
                [
                    'username' => 'hr',
                    'legacy_usernames' => ['hr_admin'],
                    'full_name' => 'HR Admin',
                    'password' => '123',
                ],
                [
                    'username' => 'hr2',
                    'legacy_usernames' => ['hr_admin_2'],
                    'full_name' => 'HR Admin 2',
                    'password' => '123',
                ],
                [
                    'username' => 'hr3',
                    'legacy_usernames' => ['hr_admin_3'],
                    'full_name' => 'HR Admin 3',
                    'password' => '123',
                ],
            ];

            foreach ($accounts as $account) {
                $existing = HRAccount::query()
                    ->where('username', $account['username'])
                    ->first();

                if (!$existing && !empty($account['legacy_usernames'])) {
                    $existing = HRAccount::query()
                        ->whereIn('username', $account['legacy_usernames'])
                        ->orderBy('id')
                        ->first();
                }

                if ($existing) {
                    $existing->forceFill([
                        'username' => $account['username'],
                        'full_name' => $account['full_name'],
                        'password' => Hash::make($account['password']),
                    ])->save();

                    continue;
                }

                HRAccount::query()->create([
                    'username' => $account['username'],
                    'full_name' => $account['full_name'],
                    'password'  => Hash::make($account['password']),
                ]);
            }
        });
    }
}
