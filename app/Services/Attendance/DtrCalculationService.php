<?php

namespace App\Services\Attendance;

use App\Models\AttendanceRawLog;
use App\Models\DailyTimeRecord;
use App\Models\EmployeeDepartmentAssignment;
use App\Models\EmployeeWorkScheduleOverride;
use App\Models\HrisEmployee;
use App\Models\LeaveApplication;
use App\Models\WorkScheduleSetting;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Service to process raw punch logs into structured Daily Time Records (DTR / Form 48).
 * Applies strict tardiness (zero grace period), work schedules, and leave status detection.
 */
class DtrCalculationService
{
    /** Standard lunch break in minutes */
    public const LUNCH_BREAK_MINUTES = 60;

    /** Maximum regular workday hours (8 hours) */
    public const STANDARD_WORKDAY_HOURS = 8.00;

    /**
     * Build list of control number variations to tolerate leading zero differences (e.g. "11790" vs "011790").
     *
     * @return array<int, string>
     */
    public function resolveControlNoVariants(string $controlNo, ?string $canonicalControlNo = null): array
    {
        $raw = trim($controlNo);
        $canonical = trim((string) ($canonicalControlNo ?? ''));
        $unpaddedRaw = ltrim($raw, '0');
        $unpaddedCanonical = $canonical !== '' ? ltrim($canonical, '0') : '';

        return array_values(array_unique(array_filter([
            $raw,
            $canonical,
            $unpaddedRaw,
            $unpaddedCanonical,
            $unpaddedRaw !== '' ? str_pad($unpaddedRaw, 6, '0', STR_PAD_LEFT) : '',
            $unpaddedCanonical !== '' ? str_pad($unpaddedCanonical, 6, '0', STR_PAD_LEFT) : '',
        ])));
    }

    /**
     * Calculate and save DTR for a specific employee on a single date.
     */
    public function calculateForEmployeeDate(string $controlNo, string $date, ?int $departmentId = null): DailyTimeRecord
    {
        $controlNo = trim($controlNo);
        $emp = HrisEmployee::findByControlNo($controlNo, true)
            ?? HrisEmployee::findByControlNo($controlNo, false);
        $canonicalControlNo = $emp?->control_no ?? $controlNo;
        $variants = $this->resolveControlNoVariants($controlNo, $canonicalControlNo);

        $dateObj = CarbonImmutable::parse($date);
        $dateStr = $dateObj->format('Y-m-d');
        $dayOfWeek = $dateObj->format('l');
        $isWeekend = $dateObj->isWeekend();

        // 1. Resolve Department ID if not provided
        if ($departmentId === null) {
            $departmentId = $this->resolveDepartmentId($canonicalControlNo, $variants);
        }

        // 2. Resolve Work Schedule (Override vs Global Default)
        $schedule = $this->resolveSchedule($canonicalControlNo, $variants);

        // 3. Fetch Raw Punches for this date
        $rawPunches = AttendanceRawLog::query()
            ->where(function ($q) use ($variants): void {
                $q->whereIn('employee_control_no', $variants)
                    ->orWhereIn('biometric_pin', $variants);
            })
            ->whereDate('punch_time', $dateStr)
            ->orderBy('punch_time')
            ->get();

        // 4. De-duplicate punches within 2 minutes of each other
        $cleanPunches = $this->filterDoubleTaps($rawPunches);

        // 5. Slot Punches into 4 Daily Slots (AM In, AM Out, PM In, PM Out)
        $slotted = $this->slotPunches($cleanPunches, $schedule);

        // 6. Check for Approved Leaves in LMS_DB
        $leaveInfo = $this->resolveApprovedLeave($canonicalControlNo, $dateStr, $variants);

        // 7. Check if existing DTR has a manual adjustment (preserve it)
        $existingDtr = DailyTimeRecord::query()
            ->whereIn('employee_control_no', $variants)
            ->where('record_date', $dateStr)
            ->first();

        // 8. Calculate Late and Undertime Minutes (Strict 0 Grace Period)
        $lateAndUndertime = $this->calculateLateAndUndertime(
            $slotted['am_arrival'],
            $slotted['am_departure'],
            $slotted['pm_arrival'],
            $slotted['pm_departure'],
            $schedule,
            $leaveInfo
        );

        // 9. Calculate Rendered Hours
        $renderedHours = $this->calculateRenderedHours(
            $slotted['am_arrival'],
            $slotted['am_departure'],
            $slotted['pm_arrival'],
            $slotted['pm_departure'],
            $leaveInfo
        );

        $today = CarbonImmutable::today();
        $isToday = $dateObj->isSameDay($today);
        $isPast = $dateObj->isBefore($today);
        $isFuture = $dateObj->isAfter($today);

        // 10. Determine Final Status
        $status = $this->determineStatus(
            $slotted,
            $leaveInfo,
            $isWeekend,
            $isPast,
            $isToday,
            $isFuture,
            $existingDtr?->is_adjusted ?? false,
            $existingDtr?->adjustment_reason
        );

        // 11. Upsert DTR in BIO_DB under canonical control number
        $payload = [
            'employee_control_no' => $canonicalControlNo,
            'department_id' => $departmentId,
            'record_date' => $dateStr,
            'day_of_week' => $dayOfWeek,
            'am_arrival' => $slotted['am_arrival'],
            'am_departure' => $slotted['am_departure'],
            'pm_arrival' => $slotted['pm_arrival'],
            'pm_departure' => $slotted['pm_departure'],
            'ot_arrival' => $slotted['ot_arrival'],
            'ot_departure' => $slotted['ot_departure'],
            'late_minutes' => $lateAndUndertime['late_minutes'],
            'undertime_minutes' => $lateAndUndertime['undertime_minutes'],
            'overtime_minutes' => $lateAndUndertime['overtime_minutes'],
            'rendered_hours' => $renderedHours,
            'status' => $status,
            'leave_type_code' => $leaveInfo['leave_type_code'] ?? null,
            'leave_application_id' => $leaveInfo['leave_application_id'] ?? null,
        ];

        // Preserve adjustment if one already existed
        if ($existingDtr && $existingDtr->is_adjusted) {
            $payload['is_adjusted'] = true;
            $payload['adjustment_reason'] = $existingDtr->adjustment_reason;
            $payload['adjustment_remarks'] = $existingDtr->adjustment_remarks;
            $payload['adjusted_by_admin_id'] = $existingDtr->adjusted_by_admin_id;
        }

        $dtr = DailyTimeRecord::query()->updateOrCreate(
            [
                'employee_control_no' => $canonicalControlNo,
                'record_date' => $dateStr,
            ],
            $payload
        );

        // Remove non-canonical duplicate DTR records for this date
        $nonCanonicalVariants = array_values(array_diff($variants, [$canonicalControlNo]));
        if (! empty($nonCanonicalVariants)) {
            DailyTimeRecord::query()
                ->whereIn('employee_control_no', $nonCanonicalVariants)
                ->where('record_date', $dateStr)
                ->delete();
        }

        // Mark raw punches as processed and heal employee_control_no to canonicalControlNo
        if ($cleanPunches->isNotEmpty()) {
            AttendanceRawLog::query()
                ->whereIn('id', $cleanPunches->pluck('id'))
                ->update([
                    'employee_control_no' => $canonicalControlNo,
                    'processed_at' => now(),
                ]);
        }

        return $dtr;
    }

    /**
     * Calculate DTR for an employee across a date range.
     *
     * @return Collection<int, DailyTimeRecord>
     */
    public function calculateForEmployeeRange(string $controlNo, string $startDate, string $endDate, ?int $departmentId = null): Collection
    {
        $start = CarbonImmutable::parse($startDate);
        $end = CarbonImmutable::parse($endDate);

        if ($start->isAfter($end)) {
            [$start, $end] = [$end, $start];
        }

        $records = collect();
        $curr = $start;

        while ($curr->isBefore($end) || $curr->isSameDay($end)) {
            $records->push($this->calculateForEmployeeDate($controlNo, $curr->format('Y-m-d'), $departmentId));
            $curr = $curr->addDay();
        }

        return $records;
    }

    /**
     * Process all unprocessed raw logs in BIO_DB and update corresponding DTRs.
     *
     * @return int Number of logs processed
     */
    public function processUnprocessedLogs(): int
    {
        $unprocessed = AttendanceRawLog::query()
            ->unprocessed()
            ->select('id', 'employee_control_no', 'biometric_pin', 'punch_time')
            ->orderBy('punch_time')
            ->limit(1000)
            ->get();

        if ($unprocessed->isEmpty()) {
            return 0;
        }

        $grouped = [];
        foreach ($unprocessed as $log) {
            $rawControlNo = trim((string) ($log->employee_control_no ?: $log->biometric_pin));
            if ($rawControlNo === '') {
                continue;
            }

            $emp = HrisEmployee::findByControlNo($rawControlNo, true)
                ?? HrisEmployee::findByControlNo($rawControlNo, false);
            $canonicalControlNo = $emp?->control_no ?? $rawControlNo;

            $date = Carbon::parse($log->punch_time)->format('Y-m-d');
            $grouped[$canonicalControlNo][$date] = true;
        }

        foreach ($grouped as $controlNo => $dates) {
            foreach (array_keys($dates) as $date) {
                try {
                    $this->calculateForEmployeeDate($controlNo, $date);
                } catch (Throwable $e) {
                    Log::error('DtrCalculationService: Error processing date', [
                        'control_no' => $controlNo,
                        'date' => $date,
                        'error' => $e->getMessage(),
                    ]);
                }
            }
        }

        return $unprocessed->count();
    }

    /**
     * Filter out accidental double taps within 2 minutes of each other.
     *
     * @param  Collection<int, AttendanceRawLog>  $punches
     * @return Collection<int, AttendanceRawLog>
     */
    public function filterDoubleTaps(Collection $punches): Collection
    {
        if ($punches->count() <= 1) {
            return $punches;
        }

        $filtered = collect();
        $lastTime = null;

        foreach ($punches as $punch) {
            $time = Carbon::parse($punch->punch_time);

            if ($lastTime === null || abs($time->diffInSeconds($lastTime)) > 120) {
                $filtered->push($punch);
                $lastTime = $time;
            }
        }

        return $filtered;
    }

    /**
     * Slot raw punches into 4 CSC Form 48 buckets based on standard time windows.
     *
     * @param  Collection<int, AttendanceRawLog>  $punches
     * @param  array{work_start_time: string, work_end_time: string, break_start_time: string, break_end_time: string}  $schedule
     * @return array{am_arrival: ?string, am_departure: ?string, pm_arrival: ?string, pm_departure: ?string, ot_arrival: ?string, ot_departure: ?string}
     */
    public function slotPunches(Collection $punches, array $schedule): array
    {
        $result = [
            'am_arrival' => null,
            'am_departure' => null,
            'pm_arrival' => null,
            'pm_departure' => null,
            'ot_arrival' => null,
            'ot_departure' => null,
        ];

        if ($punches->isEmpty()) {
            return $result;
        }

        $times = $punches->map(fn ($p) => Carbon::parse($p->punch_time)->format('H:i:s'))->values();
        $count = $times->count();

        // Standard time window thresholds
        $lunchThreshold = '12:00:00';
        $afternoonThreshold = '13:00:00';
        $departureThreshold = '16:00:00';

        if ($count === 1) {
            $time = $times[0];
            if ($time < $lunchThreshold) {
                $result['am_arrival'] = $time;
            } elseif ($time >= $departureThreshold) {
                $result['pm_departure'] = $time;
            } elseif ($time >= $afternoonThreshold) {
                $result['pm_arrival'] = $time;
            } else {
                $result['am_departure'] = $time;
            }

            return $result;
        }

        if ($count === 2) {
            $t1 = $times[0];
            $t2 = $times[1];

            if ($t1 < $lunchThreshold && $t2 >= $departureThreshold) {
                // Classic 2-punch day (morning in, afternoon out)
                $result['am_arrival'] = $t1;
                $result['pm_departure'] = $t2;
            } elseif ($t1 < $lunchThreshold && $t2 <= $afternoonThreshold) {
                // Morning in, lunch out
                $result['am_arrival'] = $t1;
                $result['am_departure'] = $t2;
            } elseif ($t1 >= $lunchThreshold && $t2 >= $departureThreshold) {
                // Lunch in, afternoon out
                $result['pm_arrival'] = $t1;
                $result['pm_departure'] = $t2;
            } else {
                $result['am_arrival'] = $t1;
                $result['pm_departure'] = $t2;
            }

            return $result;
        }

        // For 3 or more punches: Assign to closest logical slots
        foreach ($times as $time) {
            // Slot 1: AM Arrival (between 05:00 and 10:30)
            if ($result['am_arrival'] === null && $time <= '10:30:00') {
                $result['am_arrival'] = $time;

                continue;
            }

            // Slot 2: AM Departure / Lunch Out (between 11:30 and 12:45)
            if ($result['am_departure'] === null && $time >= '11:00:00' && $time <= '12:45:00') {
                $result['am_departure'] = $time;

                continue;
            }

            // Slot 3: PM Arrival / Lunch In (between 12:15 and 14:00)
            if ($result['pm_arrival'] === null && $time >= '12:15:00' && $time <= '14:00:00') {
                if ($result['am_departure'] === null || $time > $result['am_departure']) {
                    $result['pm_arrival'] = $time;

                    continue;
                }
            }

            // Slot 4: PM Departure (after 16:00)
            if ($time >= '16:00:00') {
                $result['pm_departure'] = $time;
            }
        }

        // If departure was not captured in window, pick the latest punch of the day
        if ($result['pm_departure'] === null && $count >= 3 && $times->last() > '13:30:00') {
            $result['pm_departure'] = $times->last();
        }

        return $result;
    }

    /**
     * Calculate late and undertime minutes strictly (zero grace period).
     *
     * @param  array{work_start_time: string, work_end_time: string, break_start_time: string, break_end_time: string}  $schedule
     * @param  array{is_on_leave: bool, portion: ?string}|null  $leaveInfo
     * @return array{late_minutes: int, undertime_minutes: int, overtime_minutes: int}
     */
    public function calculateLateAndUndertime(
        ?string $amIn,
        ?string $amOut,
        ?string $pmIn,
        ?string $pmOut,
        array $schedule,
        ?array $leaveInfo = null
    ): array {
        if ($leaveInfo !== null && $leaveInfo['is_on_leave'] && $leaveInfo['portion'] === 'whole') {
            return ['late_minutes' => 0, 'undertime_minutes' => 0, 'overtime_minutes' => 0];
        }

        $lateMinutes = 0;
        $undertimeMinutes = 0;
        $overtimeMinutes = 0;

        $workStart = Carbon::parse($schedule['work_start_time']);
        $breakStart = Carbon::parse($schedule['break_start_time']);
        $breakEnd = Carbon::parse($schedule['break_end_time']);
        $workEnd = Carbon::parse($schedule['work_end_time']);

        // Check Morning Late (Strict 8:00:00 AM)
        $isMorningOnLeave = $leaveInfo !== null && $leaveInfo['is_on_leave'] && $leaveInfo['portion'] === 'morning';
        if (! $isMorningOnLeave && $amIn !== null) {
            $actualAmIn = Carbon::parse($amIn);
            if ($actualAmIn->isAfter($workStart)) {
                // Strict: 8:00:01 counts as 1 minute late
                $seconds = abs($actualAmIn->diffInSeconds($workStart));
                $lateMinutes += (int) ceil($seconds / 60);
            }
        }

        // Check Lunch Late (Strict 1:00:00 PM)
        $isAfternoonOnLeave = $leaveInfo !== null && $leaveInfo['is_on_leave'] && $leaveInfo['portion'] === 'afternoon';
        if (! $isAfternoonOnLeave && $pmIn !== null) {
            $actualPmIn = Carbon::parse($pmIn);
            if ($actualPmIn->isAfter($breakEnd)) {
                $seconds = abs($actualPmIn->diffInSeconds($breakEnd));
                $lateMinutes += (int) ceil($seconds / 60);
            }
        }

        // Check AM Undertime (leaving early for lunch before 12:00 PM)
        if (! $isMorningOnLeave && $amOut !== null) {
            $actualAmOut = Carbon::parse($amOut);
            if ($actualAmOut->isBefore($breakStart)) {
                $seconds = abs($breakStart->diffInSeconds($actualAmOut));
                $undertimeMinutes += (int) ceil($seconds / 60);
            }
        }

        // Check PM Undertime (leaving early before 5:00 PM)
        if (! $isAfternoonOnLeave && $pmOut !== null) {
            $actualPmOut = Carbon::parse($pmOut);
            if ($actualPmOut->isBefore($workEnd)) {
                $seconds = abs($workEnd->diffInSeconds($actualPmOut));
                $undertimeMinutes += (int) ceil($seconds / 60);
            }
        }

        return [
            'late_minutes' => $lateMinutes,
            'undertime_minutes' => $undertimeMinutes,
            'overtime_minutes' => $overtimeMinutes,
        ];
    }

    /**
     * Calculate rendered working hours for the day.
     *
     * @param  array{is_on_leave: bool, portion: ?string}|null  $leaveInfo
     */
    public function calculateRenderedHours(?string $amIn, ?string $amOut, ?string $pmIn, ?string $pmOut, ?array $leaveInfo = null): float
    {
        if ($leaveInfo !== null && $leaveInfo['is_on_leave']) {
            if ($leaveInfo['portion'] === 'whole') {
                return self::STANDARD_WORKDAY_HOURS;
            }
            if ($leaveInfo['portion'] === 'morning' || $leaveInfo['portion'] === 'afternoon') {
                // 4 hours credited for the approved half day, plus afternoon/morning worked
                return 4.00;
            }
        }

        $totalMinutes = 0;

        // Both AM punches present
        if ($amIn !== null && $amOut !== null) {
            $tIn = Carbon::parse($amIn);
            $tOut = Carbon::parse($amOut);
            if ($tOut->isAfter($tIn)) {
                $totalMinutes += min(240, abs($tOut->diffInMinutes($tIn)));
            }
        }

        // Both PM punches present
        if ($pmIn !== null && $pmOut !== null) {
            $tIn = Carbon::parse($pmIn);
            $tOut = Carbon::parse($pmOut);
            if ($tOut->isAfter($tIn)) {
                $totalMinutes += min(240, abs($tOut->diffInMinutes($tIn)));
            }
        }

        // Fallback: 2-punch day (AM in and PM out with no lunch punches)
        if ($totalMinutes === 0 && $amIn !== null && $pmOut !== null) {
            $tIn = Carbon::parse($amIn);
            $tOut = Carbon::parse($pmOut);
            if ($tOut->isAfter($tIn)) {
                $rawSpan = abs($tOut->diffInMinutes($tIn));
                // Deduct standard 60-min lunch break
                $totalMinutes = max(0, $rawSpan - self::LUNCH_BREAK_MINUTES);
            }
        }

        // Convert to hours (capped at 8.00 standard hours)
        $hours = round($totalMinutes / 60, 2);

        return min(self::STANDARD_WORKDAY_HOURS, $hours);
    }

    /**
     * Check if the employee has an approved leave in LMS_DB for the given date.
     *
     * @param  array<int, string>  $variants
     * @return array{is_on_leave: bool, portion: string, leave_type_code: string, leave_application_id: int}|null
     */
    public function resolveApprovedLeave(string $controlNo, string $dateStr, array $variants = []): ?array
    {
        try {
            $lookupList = array_values(array_unique(array_filter([$controlNo, ...$variants])));
            $leave = LeaveApplication::query()
                ->whereIn('employee_control_no', $lookupList)
                ->where('status', 'APPROVED')
                ->where(function ($q) use ($dateStr): void {
                    $q->where(function ($sub) use ($dateStr): void {
                        $sub->whereDate('start_date', '<=', $dateStr)
                            ->whereDate('end_date', '>=', $dateStr);
                    })->orWhereJsonContains('selected_dates', $dateStr);
                })
                ->with('leaveType:id,name')
                ->first();

            if (! $leave) {
                return null;
            }

            // Determine portion: whole day vs half day
            $portion = 'whole';
            $selectedCoverages = $leave->selected_date_coverage;
            $selectedPortions = $leave->selected_date_half_day_portion;

            if (is_array($selectedCoverages) && isset($selectedCoverages[$dateStr])) {
                if ($selectedCoverages[$dateStr] === 'half') {
                    $portion = is_array($selectedPortions) && isset($selectedPortions[$dateStr])
                        ? $selectedPortions[$dateStr]
                        : 'morning';
                }
            }

            $leaveName = trim((string) ($leave->leaveType?->name ?? 'Leave'));
            $leaveCode = $this->buildLeaveCode($leaveName);

            return [
                'is_on_leave' => true,
                'portion' => $portion,
                'leave_type_code' => $leaveCode,
                'leave_application_id' => (int) $leave->id,
            ];
        } catch (Throwable $e) {
            Log::warning('DtrCalculationService: Error checking approved leave', [
                'control_no' => $controlNo,
                'date' => $dateStr,
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }

    /**
     * Determine final DTR status string.
     */
    private function determineStatus(
        array $slotted,
        ?array $leaveInfo,
        bool $isWeekend,
        bool $isPast,
        bool $isToday,
        bool $isFuture,
        bool $isAdjusted,
        ?string $adjustmentReason
    ): string {
        if ($isAdjusted) {
            return $adjustmentReason ?: DailyTimeRecord::STATUS_OFFICIAL_BUSINESS;
        }

        if ($leaveInfo !== null && $leaveInfo['is_on_leave']) {
            return $leaveInfo['portion'] === 'whole'
                ? DailyTimeRecord::STATUS_ON_LEAVE
                : DailyTimeRecord::STATUS_HALF_DAY;
        }

        $hasPunches = $slotted['am_arrival'] !== null
            || $slotted['am_departure'] !== null
            || $slotted['pm_arrival'] !== null
            || $slotted['pm_departure'] !== null;

        if ($hasPunches) {
            return DailyTimeRecord::STATUS_PRESENT;
        }

        if ($isWeekend) {
            return DailyTimeRecord::STATUS_REST_DAY;
        }

        if ($isFuture) {
            return DailyTimeRecord::STATUS_PENDING;
        }

        if ($isToday) {
            return DailyTimeRecord::STATUS_ABSENT;
        }

        return DailyTimeRecord::STATUS_ABSENT;
    }

    /**
     * Resolve working schedule for employee.
     *
     * @param  array<int, string>  $variants
     * @return array{work_start_time: string, work_end_time: string, break_start_time: string, break_end_time: string}
     */
    private function resolveSchedule(string $controlNo, array $variants = []): array
    {
        try {
            $lookupList = array_values(array_unique(array_filter([$controlNo, ...$variants])));
            $override = EmployeeWorkScheduleOverride::query()
                ->whereIn('employee_control_no', $lookupList)
                ->where('is_active', true)
                ->first();

            if ($override) {
                return [
                    'work_start_time' => $override->work_start_time ?: '08:00:00',
                    'work_end_time' => $override->work_end_time ?: '17:00:00',
                    'break_start_time' => $override->break_start_time ?: '12:00:00',
                    'break_end_time' => $override->break_end_time ?: '13:00:00',
                ];
            }

            $default = WorkScheduleSetting::query()
                ->where('setting_key', WorkScheduleSetting::GLOBAL_SETTING_KEY)
                ->first();

            if ($default) {
                return [
                    'work_start_time' => $default->work_start_time ?: '08:00:00',
                    'work_end_time' => $default->work_end_time ?: '17:00:00',
                    'break_start_time' => $default->break_start_time ?: '12:00:00',
                    'break_end_time' => $default->break_end_time ?: '13:00:00',
                ];
            }
        } catch (Throwable) {
            // Fallback to strict standard
        }

        return [
            'work_start_time' => '08:00:00',
            'work_end_time' => '17:00:00',
            'break_start_time' => '12:00:00',
            'break_end_time' => '13:00:00',
        ];
    }

    /**
     * Resolve employee's department ID from assignment table.
     *
     * @param  array<int, string>  $variants
     */
    private function resolveDepartmentId(string $controlNo, array $variants = []): ?int
    {
        try {
            $lookupList = array_values(array_unique(array_filter([$controlNo, ...$variants])));
            $assignment = EmployeeDepartmentAssignment::query()
                ->whereIn('employee_control_no', $lookupList)
                ->first();

            return $assignment ? (int) $assignment->department_id : null;
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * Build compact acronym from leave type name (e.g. Vacation Leave -> VL).
     */
    private function buildLeaveCode(string $leaveName): string
    {
        $words = preg_split('/\s+/', trim($leaveName));
        if ($words === false || count($words) === 0) {
            return 'LEAVE';
        }

        $code = '';
        foreach ($words as $w) {
            $code .= strtoupper($w[0] ?? '');
        }

        return $code !== '' ? $code : 'LEAVE';
    }
}
