<?php

namespace Database\Seeders;

use App\Models\HRAccount;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

/**
 * HR account seeder.
 */
class HRAccountSeeder extends Seeder
{
    public function run(): void
    {
        $seedPassword = trim((string) env('HR_SEEDER_PASSWORD', ''));
        if ($seedPassword === '') {
            throw new \RuntimeException('HR_SEEDER_PASSWORD is required to run HRAccountSeeder.');
        }

        $resetExistingPasswords = filter_var(
            (string) env('HR_SEEDER_RESET_PASSWORDS', 'false'),
            FILTER_VALIDATE_BOOLEAN
        );

        DB::transaction(function () use ($seedPassword, $resetExistingPasswords): void {
            $accounts = [
                [
                    'username' => 'hr',
                    'full_name' => 'HR ADMIN',
                    'position' => 'HR',
                ],
                [
                    'username' => 'hr2',
                    'full_name' => 'HR ADMIN 2',
                    'position' => 'HR',
                ],
                [
                    'username' => 'hr3',
                    'full_name' => 'HR ADMIN 3',
                    'position' => 'HR',
                ],
            ];

            foreach ($accounts as $account) {
                $existing = HRAccount::query()
                    ->where('username', $account['username'])
                    ->first();

                if ($existing) {
                    $payload = [
                        'username' => $account['username'],
                        'full_name' => $account['full_name'],
                        'position' => $account['position'],
                        'must_change_password' => true,
                    ];

                    // Keep current password by default on re-seed.
                    if ($resetExistingPasswords) {
                        $payload['password'] = Hash::make($seedPassword);
                    }

                    $existing->forceFill($payload)->save();
                    continue;
                }

                HRAccount::query()->create([
                    'username' => $account['username'],
                    'full_name' => $account['full_name'],
                    'position' => $account['position'],
                    'password' => Hash::make($seedPassword),
                    'must_change_password' => true,
                ]);
            }
        });
    }
}