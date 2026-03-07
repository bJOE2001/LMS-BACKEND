<?php

namespace Database\Seeders;

use App\Models\Department;
use App\Models\DepartmentAdmin;
use App\Models\HRAccount;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

/**
 * LOCAL DEVELOPMENT ONLY — seeds LMS_DB.
 * Creates exactly 1 department admin per department and assigns
 * random 6-digit usernames.
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

        $adminsByDepartment = DepartmentAdmin::query()
            ->whereIn('department_id', $departments->pluck('id'))
            ->get()
            ->keyBy('department_id');

        $usedUsernames = DepartmentAdmin::query()
            ->pluck('username')
            ->map(static fn($username) => (string) $username)
            ->all();
        $hrUsernames = HRAccount::query()
            ->pluck('username')
            ->map(static fn($username) => (string) $username)
            ->all();
        $usedUsernames = array_merge($usedUsernames, $hrUsernames);
        $usedUsernameLookup = array_fill_keys($usedUsernames, true);

        $created = 0;
        $updated = 0;

        \DB::transaction(function () use ($departments, $adminsByDepartment, &$usedUsernameLookup, &$created, &$updated): void {
            foreach ($departments as $department) {
                $admin = $adminsByDepartment->get($department->id);
                $newUsername = $this->generateUniqueSixDigitUsername($usedUsernameLookup);

                if ($admin) {
                    $admin->update([
                        'full_name' => "{$department->name} Admin",
                        'username' => $newUsername,
                    ]);
                    $updated++;
                    continue;
                }

                DepartmentAdmin::create([
                    'department_id' => $department->id,
                    'full_name' => "{$department->name} Admin",
                    'username' => $newUsername,
                    'password' => Hash::make('123'),
                ]);
                $created++;
            }
        });

        $this->command?->info("Department admins seeded. Created: {$created}, updated usernames: {$updated}");
    }

    private function generateUniqueSixDigitUsername(array &$usedUsernameLookup): string
    {
        for ($attempt = 0; $attempt < 5000; $attempt++) {
            $candidate = (string) random_int(100000, 999999);
            if (isset($usedUsernameLookup[$candidate])) {
                continue;
            }

            $usedUsernameLookup[$candidate] = true;
            return $candidate;
        }

        throw new \RuntimeException('Unable to generate unique 6-digit username.');
    }
}
