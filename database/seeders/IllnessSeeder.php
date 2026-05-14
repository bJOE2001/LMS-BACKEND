<?php

namespace Database\Seeders;

use App\Models\Illness;
use Illuminate\Database\Seeder;

class IllnessSeeder extends Seeder
{
    public function run(): void
    {
        $illnesses = [
            'Home Rest',
            'Flu',
            'Fever',
            'Cough and Cold',
            'Hypertension',
            'Migraine',
            'Asthma',
            'Dengue',
            'Diarrhea',
            'Urinary Tract Infection (UTI)',
        ];

        foreach ($illnesses as $name) {
            Illness::updateOrCreate(
                ['name' => $name],
                [
                    'name' => $name,
                    'is_inactive' => false,
                ]
            );
        }
    }
}