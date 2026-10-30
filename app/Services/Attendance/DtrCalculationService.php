<?php

namespace App\Services\Attendance;

use App\Models\AttendanceRawLog;
use App\Models\DailyTimeRecord;
use App\Models\EmployeeDepartmentAssignment;
use App\Models\Holiday;
use App\Models\HrisEmployee;
use App\Models\LeaveApplication;
use App\Services\WorkScheduleService;
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

    public function __construct(
        public ?WorkScheduleService $workScheduleService = null
    ) {
        $this->workScheduleService = $workScheduleService ?? app(WorkScheduleService::class);
    }

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
    public function calculateForEmployeeDate(
        string $controlNo,
        string $date,
        ?int $departmentId = null,
        ?object $cachedEmployee = null,
        ?array $cachedSchedule = null
    ): DailyTimeRecord {
        $controlNo = trim($controlNo);
        $emp = $cachedEmployee
            ?? HrisEmployee::findByControlNo($controlNo, true)
            ?? HrisEmployee::findByControlNo($controlNo, false);
        $canonicalControlNo = $emp?->control_no ?? $controlNo;
        $variants = $this->resolveControlNoVariants($controlNo, $canonicalControlNo);

        $dateObj = CarbonImmutable::parse($date);
        $dateStr = $dateObj->format('Y-m-d');
        $dayOfWeek = $dateObj->format('l');
        $isWeekend = $dateObj->isWeekend();

        // 1. Resolve Department ID from employee assignment (Home Office takes strict precedence)
        $assignedDeptId = $departmentId ?? $this->resolveDepartmentId($canonicalControlNo, $variants);
        $departmentId = $assignedDeptId;

        // 2. Resolve Work Schedule (Override vs Global Default)
        $schedule = $cachedSchedule ?? $this->resolveSchedule($canonicalControlNo, $variants);

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
        $cleanPunches = $this->filterDoubleTaps($rawPunches, $schedule);

        // 5. Slot Punches into 4 Daily Slots (AM In, AM Out, PM In, PM Out)
        $slotted = $this->slotPunches($cleanPunches, $schedule);

        // 6. Check for Approved Leaves in LMS_DB
        $leaveInfo = $this->resolveApprovedLeave($canonicalControlNo, $dateStr, $variants);

        // 6b. Check for Active Holidays in BIO_DB
        $holiday = Holiday::query()
            ->active()
            ->where(function ($q) use ($dateStr, $dateObj): void {
                $q->where('holiday_date', $dateStr)
                    ->orWhere(function ($sub) use ($dateObj): void {
                        $sub->where('is_recurring', true)
                            ->whereMonth('holiday_date', $dateObj->month)
                            ->whereDay('holiday_date', $dateObj->day);
                    });
            })
            ->first();

        // 7. Check if existing DTR has a manual adjustment (preserve it)
        $existingDtr = DailyTimeRecord::query()
            ->whereIn('employee_control_no', $variants)
            ->where('record_date', $dateStr)
            ->first();

        // If this record has a manual adjustment, manual punch times take strict precedence over raw biometric logs
        if ($existingDtr && $existingDtr->is_adjusted) {
            $slotted['am_arrival'] = $existingDtr->am_arrival;
            $slotted['am_departure'] = $existingDtr->am_departure;
            $slotted['pm_arrival'] = $existingDtr->pm_arrival;
            $slotted['pm_departure'] = $existingDtr->pm_departure;
            $slotted['ot_arrival'] = $existingDtr->ot_arrival;
            $slotted['ot_departure'] = $existingDtr->ot_departure;
        }

        // 8. Calculate Late and Undertime Minutes (Strict 0 Grace Period)
        $lateAndUndertime = $this->calculateLateAndUndertime(
            $slotted['am_arrival'],
            $slotted['am_departure'],
            $slotted['pm_arrival'],
            $slotted['pm_departure'],
            $schedule,
            $leaveInfo
        );

        // Official business / pass slip / travel order adjustments waive absence and tardiness
        if ($existingDtr && $existingDtr->is_adjusted && in_array($existingDtr->adjustment_reason, [
            DailyTimeRecord::STATUS_OFFICIAL_BUSINESS,
            DailyTimeRecord::STATUS_PASS_SLIP,
            'TRAVEL_ORDER',
            'CERTIFICATE_OF_APPEARANCE',
        ], true)) {
            $lateAndUndertime['late_minutes'] = 0;
            $lateAndUndertime['undertime_minutes'] = 0;
        }

        // Calculate Overtime Minutes if OT punches are present or preserved from adjustment
        if ($slotted['ot_arrival'] !== null && $slotted['ot_departure'] !== null) {
            try {
                $otIn = CarbonImmutable::parse($slotted['ot_arrival']);
                $otOut = CarbonImmutable::parse($slotted['ot_departure']);
                if ($otOut->isAfter($otIn)) {
                    $lateAndUndertime['overtime_minutes'] = abs($otOut->diffInMinutes($otIn));
                }
            } catch (Throwable) {
                // ignore
            }
        } elseif ($existingDtr && $existingDtr->is_adjusted && (int) ($existingDtr->overtime_minutes ?? 0) > 0) {
            $lateAndUndertime['overtime_minutes'] = (int) $existingDtr->overtime_minutes;
        }

        // 9. Calculate Rendered Hours
        $renderedHours = $this->calculateRenderedHours(
            $slotted['am_arrival'],
            $slotted['am_departure'],
            $slotted['pm_arrival'],
            $slotted['pm_departure'],
            $leaveInfo,
            $schedule
        );

        if ($existingDtr && $existingDtr->is_adjusted) {
            if ($existingDtr->adjustment_reason !== 'MANUAL_OVERRIDE' && $renderedHours <= 0) {
                $renderedHours = (float) ($schedule['working_hours_per_day'] ?? self::STANDARD_WORKDAY_HOURS);
            } elseif ($renderedHours <= 0 && (float) $existingDtr->rendered_hours > 0) {
                $renderedHours = (float) $existingDtr->rendered_hours;
            }
        }

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
            $existingDtr?->adjustment_reason,
            $holiday
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

        if ($holiday !== null && ! ($existingDtr && $existingDtr->is_adjusted)) {
            $payload['adjustment_remarks'] = $holiday->name;
        }

        // Preserve adjustment if one already existed
        if ($existingDtr && $existingDtr->is_adjusted) {
            $payload['is_adjusted'] = true;
            $payload['adjustment_reason'] = $existingDtr->adjustment_reason;
            $payload['adjustment_remarks'] = $existingDtr->adjustment_remarks;
            $payload['adjusted_by_admin_id'] = $existingDtr->adjusted_by_admin_id;
            $payload['adjusted_slots'] = $existingDtr->adjusted_slots;
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
    public function calculateForEmployeeRange(
        string $controlNo,
        string $startDate,
        string $endDate,
        ?int $departmentId = null,
        ?object $cachedEmployee = null,
        ?array $cachedSchedule = null,
        bool $forceRecalculate = false
    ): Collection {
        $start = CarbonImmutable::parse($startDate);
        $end = CarbonImmutable::parse($endDate);

        if ($start->isAfter($end)) {
            [$start, $end] = [$end, $start];
        }

        $startDateStr = $start->format('Y-m-d');
        $endDateStr = $end->format('Y-m-d');

        $emp = $cachedEmployee
            ?? HrisEmployee::findByControlNo($controlNo, true)
            ?? HrisEmployee::findByControlNo($controlNo, false);
        $canonicalControlNo = $emp?->control_no ?? $controlNo;
        $variants = $this->resolveControlNoVariants($controlNo, $canonicalControlNo);
        $assignedDeptId = $departmentId ?? $this->resolveDepartmentId($canonicalControlNo, $variants);
        $schedule = $cachedSchedule ?? $this->resolveSchedule($canonicalControlNo, $variants);

        // Preload existing DTRs for this range in 1 batch query
        $existingRecords = DailyTimeRecord::query()
            ->whereIn('employee_control_no', $variants)
            ->whereBetween('record_date', [$startDateStr, $endDateStr])
            ->get()
            ->keyBy(static fn ($r): string => substr((string) $r->record_date, 0, 10));

        // Preload dates that have unprocessed raw logs
        $unprocessedDates = AttendanceRawLog::query()
            ->unprocessed()
            ->where(function ($q) use ($variants): void {
                $q->whereIn('employee_control_no', $variants)
                    ->orWhereIn('biometric_pin', $variants);
            })
            ->whereBetween('punch_time', [$startDateStr.' 00:00:00', $endDateStr.' 23:59:59'])
            ->pluck('punch_time')
            ->map(static fn ($t): string => substr((string) $t, 0, 10))
            ->unique()
            ->flip()
            ->all();

        $records = collect();
        $curr = $start;

        while ($curr->isBefore($end) || $curr->isSameDay($end)) {
            $dateStr = $curr->format('Y-m-d');
            $existing = $existingRecords->get($dateStr);
            $hasUnprocessed = isset($unprocessedDates[$dateStr]);

            if ($existing && ! $hasUnprocessed && ! $forceRecalculate) {
                $records->push($existing);
            } else {
                $records->push(
                    $this->calculateForEmployeeDate(
                        $canonicalControlNo,
                        $dateStr,
                        $assignedDeptId,
                        $emp,
                        $schedule
                    )
                );
            }
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
     * Departure Window (Lunch Out, PM Out):
     * If an employee taps at 11:59 and again at 12:00 (or 16:59 and 17:00),
     * keep the LATEST punch so they are not penalized with false undertime.
     *
     * Arrival Window (Morning In, Lunch In):
     * Keep the EARLIEST punch so they receive credit for arriving on time.
     *
     * @param  Collection<int, AttendanceRawLog>  $punches
     * @param  array{work_start_time?: string, work_end_time?: string, break_start_time?: string, break_end_time?: string}|null  $schedule
     * @return Collection<int, AttendanceRawLog>
     */
    public function filterDoubleTaps(Collection $punches, ?array $schedule = null): Collection
    {
        if ($punches->count() <= 1) {
            return $punches;
        }

        $breakStart = $this->formatScheduleTime($schedule['break_start_time'] ?? null, '12:00:00');
        $breakEnd = $this->formatScheduleTime($schedule['break_end_time'] ?? null, '13:00:00');
        $workEnd = $this->formatScheduleTime($schedule['work_end_time'] ?? null, '17:00:00');

        $preLunchThreshold = Carbon::parse($breakStart)->subHour()->format('H:i:s');
        $breakDurationSec = abs(Carbon::parse($breakEnd)->diffInSeconds(Carbon::parse($breakStart)));
        $midLunchThreshold = Carbon::parse($breakStart)->addSeconds((int) ($breakDurationSec / 2))->format('H:i:s');
        $departureThreshold = Carbon::parse($workEnd)->subHour()->format('H:i:s');

        $filtered = collect();
        $lastTime = null;

        foreach ($punches as $punch) {
            $time = Carbon::parse($punch->punch_time);

            if ($lastTime === null || abs($time->diffInSeconds($lastTime)) > 120) {
                $filtered->push($punch);
                $lastTime = $time;
            } else {
                // Double tap detected (within 120 seconds)
                $timeStr = $time->format('H:i:s');
                $isDepartureWindow = ($timeStr >= $preLunchThreshold && $timeStr <= $midLunchThreshold)
                    || ($timeStr >= $departureThreshold);

                // In departure windows, prefer the latest punch to prevent false undertime
                if ($isDepartureWindow) {
                    $filtered->pop();
                    $filtered->push($punch);
                    $lastTime = $time;
                }
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

        // Standard time window thresholds derived from schedule:
        $breakStart = $this->formatScheduleTime($schedule['break_start_time'] ?? null, '12:00:00');
        $breakEnd = $this->formatScheduleTime($schedule['break_end_time'] ?? null, '13:00:00');
        $workEnd = $this->formatScheduleTime($schedule['work_end_time'] ?? null, '17:00:00');

        $lunchThreshold = $breakStart;
        $afternoonThreshold = $breakEnd;
        $departureThreshold = Carbon::parse($workEnd)->subHour()->format('H:i:s');
        $preLunchThreshold = Carbon::parse($breakStart)->subHour()->format('H:i:s');

        $breakDurationSec = abs(Carbon::parse($breakEnd)->diffInSeconds(Carbon::parse($breakStart)));
        $midLunchThreshold = Carbon::parse($breakStart)->addSeconds((int) ($breakDurationSec / 2))->format('H:i:s');
        $postLunchThreshold = Carbon::parse($breakEnd)->addHour()->format('H:i:s');

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
            } elseif ($t1 < $lunchThreshold && $t2 >= $preLunchThreshold && $t2 <= $afternoonThreshold) {
                // Morning in, lunch out
                $result['am_arrival'] = $t1;
                $result['am_departure'] = $t2;
            } elseif ($t1 >= $preLunchThreshold && $t2 <= $postLunchThreshold) {
                // Both punches during lunch window (e.g. 12:35 PM out, 1:00 PM in)
                $result['am_departure'] = $t1;
                $result['pm_arrival'] = $t2;
            } elseif ($t1 > $midLunchThreshold && $t2 >= $departureThreshold) {
                // Lunch in, afternoon out
                $result['pm_arrival'] = $t1;
                $result['pm_departure'] = $t2;
            } elseif ($t1 < $preLunchThreshold && $t2 < $preLunchThreshold) {
                // Multiple morning arrival taps (e.g. 7:30 AM & 8:50 AM) - take earliest arrival
                $result['am_arrival'] = $t1;
            } else {
                $result['am_arrival'] = $t1;
                $result['pm_departure'] = $t2;
            }

            return $result;
        }

        // For 3 or more punches: Assign with Smart Safeguard
        // 1. AM Arrival:
        // Earliest punch before pre-lunch threshold, OR if there are multiple morning punches before midLunchThreshold,
        // the earliest punch is AM Arrival (even if arriving late, e.g. 11:00 AM)
        $morningPunches = $times->filter(fn ($t) => $t <= $midLunchThreshold)->values();
        if ($morningPunches->count() >= 2) {
            $result['am_arrival'] = $morningPunches->first();
        } else {
            $amArrival = $times->first(fn ($t) => $t < $preLunchThreshold);
            if ($amArrival !== null) {
                $result['am_arrival'] = $amArrival;
            }
        }

        // 2. Midday Lunch Window - Smart Safeguard
        // Exclude the punch already assigned to AM Arrival
        $availableForLunch = $times->filter(fn ($t) => $t !== $result['am_arrival'])->values();
        // Lunch Out: preLunchThreshold – midLunchThreshold
        // Lunch In: midLunchThreshold – postLunchThreshold
        $lunchOutPunches = $availableForLunch->filter(fn ($t) => $t >= $preLunchThreshold && $t <= $midLunchThreshold)->values();
        $lunchInPunches = $availableForLunch->filter(fn ($t) => $t > $midLunchThreshold && $t <= $postLunchThreshold)->values();

        if ($lunchOutPunches->isNotEmpty() && $lunchInPunches->isNotEmpty()) {
            // Punches in both windows: First in Lunch-Out window is AM Departure, first in Lunch-In window is PM Arrival
            $result['am_departure'] = $lunchOutPunches->first();
            $result['pm_arrival'] = $lunchInPunches->first();
        } elseif ($lunchOutPunches->isNotEmpty() && $lunchInPunches->isEmpty()) {
            // Only punches in Lunch-Out window (e.g. employee is currently on lunch break)
            $result['am_departure'] = $lunchOutPunches->first();
            $result['pm_arrival'] = null;
        } elseif ($lunchOutPunches->isEmpty() && $lunchInPunches->count() >= 2) {
            // Smart Safeguard: Left for lunch late (both punches after mid-lunch threshold)
            $result['am_departure'] = $lunchInPunches[0];
            $result['pm_arrival'] = $lunchInPunches[1];
        } elseif ($lunchOutPunches->isEmpty() && $lunchInPunches->count() === 1) {
            // Single punch after mid-lunch threshold
            $result['pm_arrival'] = $lunchInPunches[0];
        }

        // 3. PM Departure (punches from departure threshold onwards, pick latest)
        $pmDepartures = $times->filter(fn ($t) => $t >= $departureThreshold)->values();
        if ($pmDepartures->isNotEmpty()) {
            $result['pm_departure'] = $pmDepartures->last();
        } elseif ($times->last() > Carbon::parse($breakEnd)->addMinutes(30)->format('H:i:s') && $times->last() !== $result['pm_arrival'] && $times->last() !== $result['am_departure']) {
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

        $workStart = Carbon::parse($schedule['work_start_time'])->second(0);
        $breakStart = Carbon::parse($schedule['break_start_time'])->second(0);
        $breakEnd = Carbon::parse($schedule['break_end_time'])->second(0);
        $workEnd = Carbon::parse($schedule['work_end_time'])->second(0);

        // Check Morning Late (CSC Form 48: 08:00:00 to 08:00:59 is on time, tardiness begins at 08:01:00)
        $isMorningOnLeave = $leaveInfo !== null && $leaveInfo['is_on_leave'] && $leaveInfo['portion'] === 'morning';
        if (! $isMorningOnLeave && $amIn !== null) {
            $actualAmIn = Carbon::parse($amIn)->second(0);
            if ($actualAmIn->isAfter($workStart)) {
                $lateMinutes += (int) abs($actualAmIn->diffInMinutes($workStart));
            }
        }

        // Check Lunch Late (CSC Form 48: 13:00:00 to 13:00:59 is on time, tardiness begins at 13:01:00)
        $isAfternoonOnLeave = $leaveInfo !== null && $leaveInfo['is_on_leave'] && $leaveInfo['portion'] === 'afternoon';
        if (! $isAfternoonOnLeave && $pmIn !== null) {
            $actualPmIn = Carbon::parse($pmIn)->second(0);
            if ($actualPmIn->isAfter($breakEnd)) {
                $lateMinutes += (int) abs($actualPmIn->diffInMinutes($breakEnd));
            }
        }

        // Check AM Undertime (leaving early before lunch, e.g. 11:59:xx counts as 1 minute undertime)
        if (! $isMorningOnLeave && $amOut !== null) {
            $actualAmOut = Carbon::parse($amOut)->second(0);
            if ($actualAmOut->isBefore($breakStart)) {
                $undertimeMinutes += (int) abs($breakStart->diffInMinutes($actualAmOut));
            }
        }

        // Check PM Undertime (leaving early before official work end, e.g. 16:59:xx counts as 1 minute undertime)
        if (! $isAfternoonOnLeave && $pmOut !== null) {
            $actualPmOut = Carbon::parse($pmOut)->second(0);
            if ($actualPmOut->isBefore($workEnd)) {
                $undertimeMinutes += (int) abs($workEnd->diffInMinutes($actualPmOut));
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
     * @param  array{work_start_time?: string, work_end_time?: string, break_start_time?: string, break_end_time?: string, working_hours_per_day?: float|int, half_day_leave_deduction?: float|int}|null  $schedule
     */
    public function calculateRenderedHours(
        ?string $amIn,
        ?string $amOut,
        ?string $pmIn,
        ?string $pmOut,
        ?array $leaveInfo = null,
        ?array $schedule = null
    ): float {
        $workingHoursPerDay = (float) ($schedule['working_hours_per_day'] ?? self::STANDARD_WORKDAY_HOURS);
        if ($workingHoursPerDay <= 0) {
            $workingHoursPerDay = self::STANDARD_WORKDAY_HOURS;
        }

        $halfDayRatio = (float) ($schedule['half_day_leave_deduction'] ?? 0.500);
        if ($halfDayRatio <= 0) {
            $halfDayRatio = 0.500;
        }

        $halfDayHours = $workingHoursPerDay * $halfDayRatio;
        $halfDayMinutes = (int) round($halfDayHours * 60);

        $amCapMinutes = 240;
        $pmCapMinutes = 240;
        $lunchBreakMinutes = self::LUNCH_BREAK_MINUTES;

        if ($schedule !== null) {
            $workStart = $this->formatScheduleTime($schedule['work_start_time'] ?? null, '08:00:00');
            $breakStart = $this->formatScheduleTime($schedule['break_start_time'] ?? null, '12:00:00');
            $breakEnd = $this->formatScheduleTime($schedule['break_end_time'] ?? null, '13:00:00');
            $workEnd = $this->formatScheduleTime($schedule['work_end_time'] ?? null, '17:00:00');

            $amCapMinutes = max(0, (int) Carbon::parse($breakStart)->diffInMinutes(Carbon::parse($workStart)));
            $pmCapMinutes = max(0, (int) Carbon::parse($workEnd)->diffInMinutes(Carbon::parse($breakEnd)));
            $lunchBreakMinutes = max(0, (int) Carbon::parse($breakEnd)->diffInMinutes(Carbon::parse($breakStart)));

            if ($amCapMinutes === 0) {
                $amCapMinutes = $halfDayMinutes;
            }
            if ($pmCapMinutes === 0) {
                $pmCapMinutes = $halfDayMinutes;
            }
        }

        $baseLeaveMinutes = 0;
        if ($leaveInfo !== null && $leaveInfo['is_on_leave']) {
            if ($leaveInfo['portion'] === 'whole') {
                return $workingHoursPerDay;
            }
            if ($leaveInfo['portion'] === 'morning' || $leaveInfo['portion'] === 'afternoon') {
                $baseLeaveMinutes = $halfDayMinutes;
            }
        }

        $totalMinutes = $baseLeaveMinutes;

        // Both AM punches present (only count if not on morning leave)
        $isMorningLeave = $leaveInfo !== null && $leaveInfo['is_on_leave'] && $leaveInfo['portion'] === 'morning';
        if (! $isMorningLeave && $amIn !== null && $amOut !== null) {
            $tIn = Carbon::parse($amIn)->second(0);
            $tOut = Carbon::parse($amOut)->second(0);
            if ($tOut->isAfter($tIn)) {
                $totalMinutes += min($amCapMinutes, abs($tOut->diffInMinutes($tIn)));
            }
        }

        // Both PM punches present (only count if not on afternoon leave)
        $isAfternoonLeave = $leaveInfo !== null && $leaveInfo['is_on_leave'] && $leaveInfo['portion'] === 'afternoon';
        if (! $isAfternoonLeave && $pmIn !== null && $pmOut !== null) {
            $tIn = Carbon::parse($pmIn)->second(0);
            $tOut = Carbon::parse($pmOut)->second(0);
            if ($tOut->isAfter($tIn)) {
                $totalMinutes += min($pmCapMinutes, abs($tOut->diffInMinutes($tIn)));
            }
        }

        // Fallback: 2-punch day (AM in and PM out with no lunch punches)
        if ($totalMinutes === 0 && $amIn !== null && $pmOut !== null) {
            $tIn = Carbon::parse($amIn)->second(0);
            $tOut = Carbon::parse($pmOut)->second(0);
            if ($tOut->isAfter($tIn)) {
                $rawSpan = abs($tOut->diffInMinutes($tIn));
                // Deduct lunch break
                $totalMinutes = max(0, $rawSpan - $lunchBreakMinutes);
            }
        }

        // Convert to hours (capped at $workingHoursPerDay)
        $hours = round($totalMinutes / 60, 2);

        return min($workingHoursPerDay, max(0.0, $hours));
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

            $isHalfDay = false;
            if (is_array($selectedCoverages) && isset($selectedCoverages[$dateStr])) {
                $isHalfDay = strtolower(trim((string) $selectedCoverages[$dateStr])) === 'half';
            } elseif ((float) ($leave->total_days ?? 0) > 0 && (float) ($leave->total_days ?? 0) <= 0.5) {
                $isHalfDay = true;
            }

            if ($isHalfDay) {
                $rawPortion = is_array($selectedPortions) && isset($selectedPortions[$dateStr])
                    ? strtolower(trim((string) $selectedPortions[$dateStr]))
                    : 'morning';

                if (in_array($rawPortion, ['pm', 'afternoon', 'p', 'afternoon (pm)'], true)) {
                    $portion = 'afternoon';
                } else {
                    $portion = 'morning';
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
        ?string $adjustmentReason,
        ?Holiday $holiday = null
    ): string {
        if ($isAdjusted) {
            if ($adjustmentReason === 'MANUAL_OVERRIDE') {
                $hasPunches = $slotted['am_arrival'] !== null
                    || $slotted['am_departure'] !== null
                    || $slotted['pm_arrival'] !== null
                    || $slotted['pm_departure'] !== null;

                return $hasPunches ? DailyTimeRecord::STATUS_PRESENT : DailyTimeRecord::STATUS_ABSENT;
            }

            return $adjustmentReason ?: DailyTimeRecord::STATUS_OFFICIAL_BUSINESS;
        }

        if ($holiday !== null) {
            return DailyTimeRecord::STATUS_HOLIDAY;
        }

        if ($leaveInfo !== null && $leaveInfo['is_on_leave']) {
            if ($leaveInfo['portion'] === 'morning') {
                return DailyTimeRecord::STATUS_ON_LEAVE_AM;
            }
            if ($leaveInfo['portion'] === 'afternoon') {
                return DailyTimeRecord::STATUS_ON_LEAVE_PM;
            }

            return DailyTimeRecord::STATUS_ON_LEAVE;
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
     * Resolve working schedule for employee using WorkScheduleService as the single source of truth.
     *
     * @param  array<int, string>  $variants
     * @return array{
     *   work_start_time: string,
     *   work_end_time: string,
     *   break_start_time: string,
     *   break_end_time: string,
     *   working_hours_per_day: float,
     *   whole_day_leave_deduction: float,
     *   half_day_leave_deduction: float,
     *   source: string
     * }
     */
    public function resolveSchedule(string $controlNo, array $variants = []): array
    {
        try {
            $lookupList = array_values(array_unique(array_filter([$controlNo, ...$variants])));
            $resolved = null;
            foreach ($lookupList as $candidate) {
                $candidateResolved = $this->workScheduleService->resolveScheduleForEmployee($candidate);
                if (($candidateResolved['source'] ?? '') === 'employee') {
                    $resolved = $candidateResolved;
                    break;
                }
            }

            $resolved ??= $this->workScheduleService->resolveScheduleForEmployee($controlNo);

            return [
                'work_start_time' => $this->formatScheduleTime($resolved['work_start_time'] ?? null, '08:00:00'),
                'work_end_time' => $this->formatScheduleTime($resolved['work_end_time'] ?? null, '17:00:00'),
                'break_start_time' => $this->formatScheduleTime($resolved['break_start_time'] ?? null, '12:00:00'),
                'break_end_time' => $this->formatScheduleTime($resolved['break_end_time'] ?? null, '13:00:00'),
                'working_hours_per_day' => (float) ($resolved['working_hours_per_day'] ?? WorkScheduleService::STANDARD_WORKDAY_HOURS),
                'whole_day_leave_deduction' => (float) ($resolved['whole_day_leave_deduction'] ?? 1.0),
                'half_day_leave_deduction' => (float) ($resolved['half_day_leave_deduction'] ?? 0.5),
                'source' => $resolved['source'] ?? 'default',
            ];
        } catch (Throwable) {
            // Fallback to strict standard
        }

        return [
            'work_start_time' => '08:00:00',
            'work_end_time' => '17:00:00',
            'break_start_time' => '12:00:00',
            'break_end_time' => '13:00:00',
            'working_hours_per_day' => WorkScheduleService::STANDARD_WORKDAY_HOURS,
            'whole_day_leave_deduction' => 1.0,
            'half_day_leave_deduction' => 0.5,
            'source' => 'default',
        ];
    }

    private function formatScheduleTime(?string $time, string $default): string
    {
        if ($time === null || trim($time) === '') {
            return $default;
        }

        try {
            return Carbon::parse($time)->format('H:i:s');
        } catch (Throwable) {
            return $default;
        }
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
     * Build compact acronym from leave type name (e.g. Vacation Leave -> VL, Mandatory / Forced Leave -> FL).
     */
    private function buildLeaveCode(string $leaveName): string
    {
        $normalized = strtoupper(trim($leaveName));

        $knownCodes = [
            'VACATION LEAVE' => 'VL',
            'SICK LEAVE' => 'SL',
            'MANDATORY / FORCED LEAVE' => 'FL',
            'MANDATORY LEAVE' => 'FL',
            'FORCED LEAVE' => 'FL',
            'SPECIAL PRIVILEGE LEAVE' => 'SPL',
            'MATERNITY LEAVE' => 'ML',
            'PATERNITY LEAVE' => 'PL',
            'SOLO PARENT LEAVE' => 'SPL',
            'STUDY LEAVE' => 'STL',
            'VAWC LEAVE' => 'VAWC',
            'REHABILITATION PRIVILEGE' => 'RP',
            'SPECIAL EMERGENCY LEAVE' => 'SEL',
            'ADOPTION LEAVE' => 'AL',
            'COMPENSATORY TIME-OFF' => 'CTO',
            'COMPENSATORY OVERTIME CREDITS' => 'COC',
        ];

        if (isset($knownCodes[$normalized])) {
            return $knownCodes[$normalized];
        }

        $clean = preg_replace('/[^\w\s]/', ' ', $normalized) ?? $normalized;
        $words = preg_split('/\s+/', trim($clean));
        if ($words === false || count($words) === 0) {
            return 'LEAVE';
        }

        $code = '';
        foreach ($words as $w) {
            if ($w !== '') {
                $code .= $w[0];
            }
        }

        return $code !== '' ? $code : 'LEAVE';
    }
}
