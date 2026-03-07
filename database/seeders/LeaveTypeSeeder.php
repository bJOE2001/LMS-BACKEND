<?php

namespace Database\Seeders;

use App\Models\LeaveType;
use Illuminate\Database\Seeder;

/**
 * Seed all leave types: ACCRUED, RESETTABLE, EVENT.
 * LOCAL LMS_DB only.
 */
class LeaveTypeSeeder extends Seeder
{
    public function run(): void
    {
        $types = [
            // ─── ACCRUED (Monthly Accrual: 1.25 every 1st day) ───────────────
            [
                'name'                 => 'Vacation Leave',
                'category'             => 'ACCRUED',
                'accrual_rate'         => 1.25,
                'accrual_day_of_month' => 1,
                'max_days'             => null,
                'is_credit_based'      => true,
                'resets_yearly'        => false,
                'requires_documents'   => false,
                'description'          => 'Monthly accrual of 1.25 days. Accumulates over time.',
            ],
            [
                'name'                 => 'Sick Leave',
                'category'             => 'ACCRUED',
                'accrual_rate'         => 1.25,
                'accrual_day_of_month' => 1,
                'max_days'             => null,
                'is_credit_based'      => true,
                'resets_yearly'        => false,
                'requires_documents'   => false,
                'description'          => 'Monthly accrual of 1.25 days. Accumulates over time.',
            ],

            // ─── RESETTABLE (Yearly Reset — No Monthly Accrual) ──────────────
            [
                'name'                 => 'Mandatory / Forced Leave',
                'category'             => 'RESETTABLE',
                'accrual_rate'         => null,
                'accrual_day_of_month' => null,
                'max_days'             => 5,
                'is_credit_based'      => true,
                'resets_yearly'        => true,
                'requires_documents'   => false,
                'description'          => '5 days per year. Resets every January 1.',
            ],
            [
                'name'                 => 'Special Privilege Leave',
                'category'             => 'RESETTABLE',
                'accrual_rate'         => null,
                'accrual_day_of_month' => null,
                'max_days'             => 3,
                'is_credit_based'      => true,
                'resets_yearly'        => true,
                'requires_documents'   => false,
                'description'          => '3 days per year. Resets every January 1.',
            ],
            [
                'name'                 => 'Solo Parent Leave',
                'category'             => 'RESETTABLE',
                'accrual_rate'         => null,
                'accrual_day_of_month' => null,
                'max_days'             => 7,
                'is_credit_based'      => true,
                'resets_yearly'        => true,
                'requires_documents'   => true,
                'description'          => '7 days per year under Solo Parent Welfare Act. Resets every January 1.',
            ],
            [
                'name'                 => 'Special Emergency (Calamity) Leave',
                'category'             => 'RESETTABLE',
                'accrual_rate'         => null,
                'accrual_day_of_month' => null,
                'max_days'             => 5,
                'is_credit_based'      => true,
                'resets_yearly'        => true,
                'requires_documents'   => false,
                'description'          => '5 days per year for calamity situations. Resets every January 1.',
            ],
            [
                'name'                 => 'MCO6 Leave',
                'category'             => 'RESETTABLE',
                'accrual_rate'         => null,
                'accrual_day_of_month' => null,
                'max_days'             => 3,
                'is_credit_based'      => true,
                'resets_yearly'        => true,
                'requires_documents'   => false,
                'description'          => '3 days per year. Resets every January 1.',
            ],

            // ─── EVENT-BASED (Triggered by Event — No Accrual, No Reset) ─────
            [
                'name'                 => 'Maternity Leave',
                'category'             => 'EVENT',
                'accrual_rate'         => null,
                'accrual_day_of_month' => null,
                'max_days'             => 105,
                'is_credit_based'      => false,
                'resets_yearly'        => false,
                'requires_documents'   => true,
                'description'          => '105 days for live birth. Requires medical documents.',
            ],
            [
                'name'                 => 'Paternity Leave',
                'category'             => 'EVENT',
                'accrual_rate'         => null,
                'accrual_day_of_month' => null,
                'max_days'             => 7,
                'is_credit_based'      => false,
                'resets_yearly'        => false,
                'requires_documents'   => true,
                'description'          => '7 days per qualifying birth event.',
            ],
            [
                'name'                 => 'Adoption Leave',
                'category'             => 'EVENT',
                'accrual_rate'         => null,
                'accrual_day_of_month' => null,
                'max_days'             => null,
                'is_credit_based'      => false,
                'resets_yearly'        => false,
                'requires_documents'   => true,
                'description'          => 'Leave for adoption proceedings. Duration per applicable law.',
            ],
            [
                'name'                 => '10-Day VAWC Leave',
                'category'             => 'EVENT',
                'accrual_rate'         => null,
                'accrual_day_of_month' => null,
                'max_days'             => 10,
                'is_credit_based'      => false,
                'resets_yearly'        => false,
                'requires_documents'   => true,
                'description'          => '10 days under RA 9262. Requires barangay protection order or court order.',
            ],
            [
                'name'                 => 'Rehabilitation Leave',
                'category'             => 'EVENT',
                'accrual_rate'         => null,
                'accrual_day_of_month' => null,
                'max_days'             => null,
                'is_credit_based'      => false,
                'resets_yearly'        => false,
                'requires_documents'   => true,
                'description'          => 'For work-related illness or injury rehabilitation.',
            ],
            [
                'name'                 => 'Study Leave',
                'category'             => 'EVENT',
                'accrual_rate'         => null,
                'accrual_day_of_month' => null,
                'max_days'             => null,
                'is_credit_based'      => false,
                'resets_yearly'        => false,
                'requires_documents'   => true,
                'description'          => 'For completion of master\'s degree or BAR/Board review.',
            ],
            [
                'name'                 => 'Special Leave Benefits for Women',
                'category'             => 'EVENT',
                'accrual_rate'         => null,
                'accrual_day_of_month' => null,
                'max_days'             => 60,
                'is_credit_based'      => false,
                'resets_yearly'        => false,
                'requires_documents'   => true,
                'description'          => 'Up to 60 days for gynecological surgery under RA 9710.',
            ],
            [
                'name'                 => 'Terminal Leave',
                'category'             => 'EVENT',
                'accrual_rate'         => null,
                'accrual_day_of_month' => null,
                'max_days'             => null,
                'is_credit_based'      => false,
                'resets_yearly'        => false,
                'requires_documents'   => true,
                'description'          => 'Applied for upon separation from service.',
            ],
            [
                'name'                 => 'Monetization of Leave Credits',
                'category'             => 'EVENT',
                'accrual_rate'         => null,
                'accrual_day_of_month' => null,
                'max_days'             => null,
                'is_credit_based'      => false,
                'resets_yearly'        => false,
                'requires_documents'   => true,
                'description'          => 'Conversion of accumulated leave credits to cash.',
            ],
        ];

        foreach ($types as $type) {
            LeaveType::updateOrCreate(
                ['name' => $type['name']],
                $type,
            );
        }
    }
}
