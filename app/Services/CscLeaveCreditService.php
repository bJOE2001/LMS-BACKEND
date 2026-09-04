<?php

namespace App\Services;

/**
 * Service to compute monthly earned leave credits according to the official
 * Civil Service Commission (CSC) Omnibus Rules on Leave (CSC MC No. 41, s. 1998,
 * as amended by CSC MC No. 14, s. 1999 — Table III).
 */
class CscLeaveCreditService
{
    /**
     * Standard monthly accrual rate for full service (30 calendar days).
     */
    public const STANDARD_MONTHLY_RATE = 1.250;

    /**
     * Official CSC Table III lookup.
     * Maps days on Leave Without Pay (LWOP) in 0.5 day steps (0.0 to 30.0)
     * to leave credits earned for the month.
     *
     * @var array<string, float>
     */
    public const TABLE_III = [
        '0.0' => 1.250,
        '0.5' => 1.229,
        '1.0' => 1.208,
        '1.5' => 1.188,
        '2.0' => 1.167,
        '2.5' => 1.146,
        '3.0' => 1.125,
        '3.5' => 1.104,
        '4.0' => 1.083,
        '4.5' => 1.063,
        '5.0' => 1.042,
        '5.5' => 1.021,
        '6.0' => 1.000,
        '6.5' => 0.979,
        '7.0' => 0.958,
        '7.5' => 0.938,
        '8.0' => 0.917,
        '8.5' => 0.896,
        '9.0' => 0.875,
        '9.5' => 0.854,
        '10.0' => 0.833,
        '10.5' => 0.813,
        '11.0' => 0.792,
        '11.5' => 0.771,
        '12.0' => 0.750,
        '12.5' => 0.729,
        '13.0' => 0.708,
        '13.5' => 0.687,
        '14.0' => 0.667,
        '14.5' => 0.646,
        '15.0' => 0.625,
        '15.5' => 0.604,
        '16.0' => 0.583,
        '16.5' => 0.562,
        '17.0' => 0.542,
        '17.5' => 0.521,
        '18.0' => 0.500,
        '18.5' => 0.479,
        '19.0' => 0.458,
        '19.5' => 0.437,
        '20.0' => 0.417,
        '20.5' => 0.396,
        '21.0' => 0.375,
        '21.5' => 0.354,
        '22.0' => 0.333,
        '22.5' => 0.312,
        '23.0' => 0.292,
        '23.5' => 0.271,
        '24.0' => 0.250,
        '24.5' => 0.229,
        '25.0' => 0.208,
        '25.5' => 0.187,
        '26.0' => 0.167,
        '26.5' => 0.146,
        '27.0' => 0.125,
        '27.5' => 0.104,
        '28.0' => 0.083,
        '28.5' => 0.062,
        '29.0' => 0.042,
        '29.5' => 0.021,
        '30.0' => 0.000,
    ];

    /**
     * Compute monthly earned leave credits given total LWOP days incurred in the month.
     *
     * @param  float  $lwopDays  Total number of LWOP days in the month.
     * @param  float  $baseAccrualRate  Nominal full-month accrual rate (default 1.250).
     * @return float Earned credits for the month, rounded to 3 decimal places.
     */
    public function computeMonthlyAccrual(float $lwopDays, float $baseAccrualRate = self::STANDARD_MONTHLY_RATE): float
    {
        if ($lwopDays <= 0.0) {
            return round($baseAccrualRate, 3);
        }

        if ($lwopDays >= 30.0) {
            return 0.0;
        }

        $roundedLwop = round($lwopDays * 2) / 2;
        $key = sprintf('%.1f', $roundedLwop);

        if (abs($lwopDays - $roundedLwop) < 0.001 && isset(self::TABLE_III[$key])) {
            $standardCredit = self::TABLE_III[$key];
        } else {
            // For custom non-half-day fractions, apply the CSC standard proportion:
            // Days Present / 24 (or Days Present * (1.25 / 30)) rounded to 3 decimals.
            $daysPresent = max(0.0, 30.0 - $lwopDays);
            $standardCredit = round(($daysPresent / 30.0) * self::STANDARD_MONTHLY_RATE, 3);
        }

        if (abs($baseAccrualRate - self::STANDARD_MONTHLY_RATE) < 0.0001) {
            return $standardCredit;
        }

        // Scale proportionally if the leave type has a non-standard base accrual rate.
        return round(($standardCredit / self::STANDARD_MONTHLY_RATE) * $baseAccrualRate, 3);
    }
}
