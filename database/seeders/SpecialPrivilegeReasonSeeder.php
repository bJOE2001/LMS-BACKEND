<?php

namespace Database\Seeders;

use App\Models\SpecialPrivilegeReason;
use Illuminate\Database\Seeder;

class SpecialPrivilegeReasonSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $reasons = [
            'Personal milestones',
            'Parental obligations',
            'Filial obligations',
            'Domestic emergencies',
            'Personal transactions',
            'Calamity',
        ];

        foreach ($reasons as $description) {
            SpecialPrivilegeReason::updateOrCreate(
                ['description' => $description],
                [
                    'is_inactive' => false,
                ]
            );
        }
    }
}
