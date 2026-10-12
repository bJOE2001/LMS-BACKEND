<?php

namespace Tests\Feature;

use App\Models\AttendanceRawLog;
use App\Models\DailyTimeRecord;
use App\Models\EmployeeDepartmentAssignment;
use App\Models\LeaveApplication;
use App\Models\LeaveType;
use App\Services\Attendance\DtrCalculationService;
use Carbon\Carbon;
use Tests\TestCase;

class DtrCalculationTest extends TestCase
{
    private const TEST_CONTROL_NO = 'TEST99001';

    private const TEST_DATE = '2026-09-22'; // Tuesday (weekday)

    private const TEST_WEEKEND_DATE = '2026-09-20'; // Sunday (weekend)

    protected function setUp(): void
    {
        parent::setUp();
        $this->cleanupTestData();
    }

    protected function tearDown(): void
    {
        $this->cleanupTestData();
        parent::tearDown();
    }

    private function cleanupTestData(): void
    {
        AttendanceRawLog::query()
            ->where('biometric_pin', self::TEST_CONTROL_NO)
            ->orWhere('employee_control_no', self::TEST_CONTROL_NO)
            ->delete();

        DailyTimeRecord::query()
            ->where('employee_control_no', self::TEST_CONTROL_NO)
            ->delete();

        LeaveApplication::query()
            ->where('employee_control_no', self::TEST_CONTROL_NO)
            ->delete();

        EmployeeDepartmentAssignment::query()
            ->where('employee_control_no', self::TEST_CONTROL_NO)
            ->delete();
    }

    public function test_dtr_on_time_standard_punches_yields_zero_late(): void
    {
        $service = app(DtrCalculationService::class);

        // Morning In: 07:55 AM (on time)
        AttendanceRawLog::query()->create([
            'biometric_pin' => self::TEST_CONTROL_NO,
            'employee_control_no' => self::TEST_CONTROL_NO,
            'punch_time' => self::TEST_DATE.' 07:55:00',
            'punch_state' => 0,
            'verify_type' => 15,
            'sync_source' => 'ADMS',
        ]);

        // Lunch Out: 12:05 PM (on time)
        AttendanceRawLog::query()->create([
            'biometric_pin' => self::TEST_CONTROL_NO,
            'employee_control_no' => self::TEST_CONTROL_NO,
            'punch_time' => self::TEST_DATE.' 12:05:00',
            'punch_state' => 1,
            'verify_type' => 15,
            'sync_source' => 'ADMS',
        ]);

        // Lunch In: 12:55 PM (on time)
        AttendanceRawLog::query()->create([
            'biometric_pin' => self::TEST_CONTROL_NO,
            'employee_control_no' => self::TEST_CONTROL_NO,
            'punch_time' => self::TEST_DATE.' 12:55:00',
            'punch_state' => 0,
            'verify_type' => 15,
            'sync_source' => 'ADMS',
        ]);

        // Afternoon Out: 17:05 PM (on time)
        AttendanceRawLog::query()->create([
            'biometric_pin' => self::TEST_CONTROL_NO,
            'employee_control_no' => self::TEST_CONTROL_NO,
            'punch_time' => self::TEST_DATE.' 17:05:00',
            'punch_state' => 1,
            'verify_type' => 15,
            'sync_source' => 'ADMS',
        ]);

        $dtr = $service->calculateForEmployeeDate(self::TEST_CONTROL_NO, self::TEST_DATE);

        $this->assertNotNull($dtr);
        $this->assertSame(self::TEST_CONTROL_NO, $dtr->employee_control_no);
        $this->assertSame('07:55:00', $dtr->am_arrival);
        $this->assertSame('12:05:00', $dtr->am_departure);
        $this->assertSame('12:55:00', $dtr->pm_arrival);
        $this->assertSame('17:05:00', $dtr->pm_departure);
        $this->assertSame(0, $dtr->late_minutes);
        $this->assertSame(0, $dtr->undertime_minutes);
        $this->assertEquals(8.00, (float) $dtr->rendered_hours);
        $this->assertSame(DailyTimeRecord::STATUS_PRESENT, $dtr->status);
    }

    public function test_dtr_calculates_strict_late_and_undertime(): void
    {
        $service = app(DtrCalculationService::class);

        // Morning In: 08:14 AM (14 mins late)
        AttendanceRawLog::query()->create([
            'biometric_pin' => self::TEST_CONTROL_NO,
            'employee_control_no' => self::TEST_CONTROL_NO,
            'punch_time' => self::TEST_DATE.' 08:14:00',
            'punch_state' => 0,
            'verify_type' => 15,
            'sync_source' => 'ADMS',
        ]);

        // Lunch Out: 11:45 AM (15 mins undertime)
        AttendanceRawLog::query()->create([
            'biometric_pin' => self::TEST_CONTROL_NO,
            'employee_control_no' => self::TEST_CONTROL_NO,
            'punch_time' => self::TEST_DATE.' 11:45:00',
            'punch_state' => 1,
            'verify_type' => 15,
            'sync_source' => 'ADMS',
        ]);

        // Lunch In: 13:10 PM (10 mins late)
        AttendanceRawLog::query()->create([
            'biometric_pin' => self::TEST_CONTROL_NO,
            'employee_control_no' => self::TEST_CONTROL_NO,
            'punch_time' => self::TEST_DATE.' 13:10:00',
            'punch_state' => 0,
            'verify_type' => 15,
            'sync_source' => 'ADMS',
        ]);

        // Afternoon Out: 16:50 PM (10 mins undertime)
        AttendanceRawLog::query()->create([
            'biometric_pin' => self::TEST_CONTROL_NO,
            'employee_control_no' => self::TEST_CONTROL_NO,
            'punch_time' => self::TEST_DATE.' 16:50:00',
            'punch_state' => 1,
            'verify_type' => 15,
            'sync_source' => 'ADMS',
        ]);

        $dtr = $service->calculateForEmployeeDate(self::TEST_CONTROL_NO, self::TEST_DATE);

        $this->assertSame(24, $dtr->late_minutes, '14 mins morning late + 10 mins lunch late = 24');
        $this->assertSame(25, $dtr->undertime_minutes, '15 mins lunch undertime + 10 mins afternoon undertime = 25');
    }

    public function test_dtr_approved_leave_marks_status_on_leave_with_zero_late(): void
    {
        $service = app(DtrCalculationService::class);
        $leaveType = LeaveType::query()->first();

        // Create an approved leave covering this date
        LeaveApplication::query()->create([
            'employee_control_no' => self::TEST_CONTROL_NO,
            'leave_type_id' => $leaveType?->id ?? 1,
            'start_date' => self::TEST_DATE,
            'end_date' => self::TEST_DATE,
            'total_days' => 1.0,
            'status' => 'APPROVED',
            'selected_dates' => [self::TEST_DATE],
            'selected_date_coverage' => [self::TEST_DATE => 'whole'],
        ]);

        $dtr = $service->calculateForEmployeeDate(self::TEST_CONTROL_NO, self::TEST_DATE);

        $this->assertSame(DailyTimeRecord::STATUS_ON_LEAVE, $dtr->status);
        $this->assertSame(0, $dtr->late_minutes);
        $this->assertSame(0, $dtr->undertime_minutes);
        $this->assertEquals(8.00, (float) $dtr->rendered_hours);
        $this->assertNotNull($dtr->leave_application_id);
    }

    public function test_dtr_filters_double_taps_within_two_minutes(): void
    {
        $service = app(DtrCalculationService::class);

        // Tap 1: 07:55:10
        AttendanceRawLog::query()->create([
            'biometric_pin' => self::TEST_CONTROL_NO,
            'employee_control_no' => self::TEST_CONTROL_NO,
            'punch_time' => self::TEST_DATE.' 07:55:10',
            'punch_state' => 0,
            'verify_type' => 15,
            'sync_source' => 'ADMS',
        ]);

        // Tap 2 (accidental double scan 30 seconds later): 07:55:40
        AttendanceRawLog::query()->create([
            'biometric_pin' => self::TEST_CONTROL_NO,
            'employee_control_no' => self::TEST_CONTROL_NO,
            'punch_time' => self::TEST_DATE.' 07:55:40',
            'punch_state' => 0,
            'verify_type' => 15,
            'sync_source' => 'ADMS',
        ]);

        $punches = AttendanceRawLog::query()
            ->where('employee_control_no', self::TEST_CONTROL_NO)
            ->orderBy('punch_time')
            ->get();

        $cleanPunches = $service->filterDoubleTaps($punches);

        $this->assertCount(1, $cleanPunches, 'Second tap within 2 minutes should be ignored');
        $this->assertSame(self::TEST_DATE.' 07:55:10', Carbon::parse($cleanPunches->first()->punch_time)->format('Y-m-d H:i:s'));
    }

    public function test_dtr_weekend_without_punches_marks_rest_day(): void
    {
        $service = app(DtrCalculationService::class);

        $dtr = $service->calculateForEmployeeDate(self::TEST_CONTROL_NO, self::TEST_WEEKEND_DATE);

        $this->assertSame(DailyTimeRecord::STATUS_REST_DAY, $dtr->status);
        $this->assertSame(0, $dtr->late_minutes);
        $this->assertEquals(0.00, (float) $dtr->rendered_hours);
    }

    public function test_dtr_resolves_leading_zero_padded_and_unpadded_control_numbers(): void
    {
        $service = app(DtrCalculationService::class);
        $canonicalControlNo = '099001';
        $unpaddedControlNo = '99001';

        try {
            // Clean up any test records
            AttendanceRawLog::query()
                ->whereIn('biometric_pin', [$canonicalControlNo, $unpaddedControlNo])
                ->orWhereIn('employee_control_no', [$canonicalControlNo, $unpaddedControlNo])
                ->delete();
            DailyTimeRecord::query()
                ->whereIn('employee_control_no', [$canonicalControlNo, $unpaddedControlNo])
                ->delete();

            // Device pushes unpadded pin (99001)
            AttendanceRawLog::query()->create([
                'biometric_pin' => $unpaddedControlNo,
                'employee_control_no' => $unpaddedControlNo,
                'punch_time' => self::TEST_DATE.' 07:58:00',
                'punch_state' => 0,
                'verify_type' => 15,
                'sync_source' => 'ADMS',
            ]);

            AttendanceRawLog::query()->create([
                'biometric_pin' => $unpaddedControlNo,
                'employee_control_no' => $unpaddedControlNo,
                'punch_time' => self::TEST_DATE.' 17:02:00',
                'punch_state' => 1,
                'verify_type' => 15,
                'sync_source' => 'ADMS',
            ]);

            // Calculate using padded control number
            $dtr = $service->calculateForEmployeeDate($canonicalControlNo, self::TEST_DATE);

            $this->assertNotNull($dtr);
            $this->assertSame(DailyTimeRecord::STATUS_PRESENT, $dtr->status);
            $this->assertSame('07:58:00', $dtr->am_arrival);
            $this->assertSame('17:02:00', $dtr->pm_departure);

            // Raw logs should be healed to the canonical control number
            $rawLogs = AttendanceRawLog::query()
                ->whereIn('biometric_pin', [$canonicalControlNo, $unpaddedControlNo])
                ->get();
            foreach ($rawLogs as $log) {
                $this->assertSame($canonicalControlNo, $log->employee_control_no);
                $this->assertNotNull($log->processed_at);
            }
        } finally {
            AttendanceRawLog::query()
                ->whereIn('biometric_pin', [$canonicalControlNo, $unpaddedControlNo])
                ->orWhereIn('employee_control_no', [$canonicalControlNo, $unpaddedControlNo])
                ->delete();
            DailyTimeRecord::query()
                ->whereIn('employee_control_no', [$canonicalControlNo, $unpaddedControlNo])
                ->delete();
        }
    }

    public function test_cross_office_punch_saves_to_assigned_home_department(): void
    {
        $service = app(DtrCalculationService::class);
        $homeDepartmentId = 19; // IT Office
        $engineeringDeviceSn = 'ENG-MB360-TEST01';
        $itDeviceSn = 'IT-MB360-TEST01';

        // Assign employee to IT Office (Dept 19)
        EmployeeDepartmentAssignment::query()->create([
            'employee_control_no' => self::TEST_CONTROL_NO,
            'department_id' => $homeDepartmentId,
            'assigned_by_user_id' => 1,
        ]);

        // Employee punches in at Engineering Office (different office!)
        AttendanceRawLog::query()->create([
            'device_serial_number' => $engineeringDeviceSn,
            'biometric_pin' => self::TEST_CONTROL_NO,
            'employee_control_no' => self::TEST_CONTROL_NO,
            'punch_time' => self::TEST_DATE.' 07:50:00',
            'punch_state' => 0,
            'verify_type' => 15,
            'sync_source' => 'ADMS',
        ]);

        // Employee punches out at IT Office (home office)
        AttendanceRawLog::query()->create([
            'device_serial_number' => $itDeviceSn,
            'biometric_pin' => self::TEST_CONTROL_NO,
            'employee_control_no' => self::TEST_CONTROL_NO,
            'punch_time' => self::TEST_DATE.' 17:05:00',
            'punch_state' => 1,
            'verify_type' => 15,
            'sync_source' => 'ADMS',
        ]);

        // Calculate DTR
        $dtr = $service->calculateForEmployeeDate(self::TEST_CONTROL_NO, self::TEST_DATE);

        $this->assertNotNull($dtr);
        // Verify DTR is strictly assigned to IT Office (Dept 19), NOT Engineering
        $this->assertSame($homeDepartmentId, (int) $dtr->department_id);
        $this->assertSame(DailyTimeRecord::STATUS_PRESENT, $dtr->status);
        $this->assertSame('07:50:00', $dtr->am_arrival);
        $this->assertSame('17:05:00', $dtr->pm_departure);
    }

    public function test_dtr_earliest_am_arrival_recorded_and_accidental_late_morning_punch_ignored(): void
    {
        $service = app(DtrCalculationService::class);

        // Morning In 1: 07:30:00 (early, on time)
        AttendanceRawLog::query()->create([
            'biometric_pin' => self::TEST_CONTROL_NO,
            'employee_control_no' => self::TEST_CONTROL_NO,
            'punch_time' => self::TEST_DATE.' 07:30:00',
            'punch_state' => 0,
            'verify_type' => 15,
            'sync_source' => 'ADMS',
        ]);

        // Morning In 2 (accidental punch): 08:50:00
        AttendanceRawLog::query()->create([
            'biometric_pin' => self::TEST_CONTROL_NO,
            'employee_control_no' => self::TEST_CONTROL_NO,
            'punch_time' => self::TEST_DATE.' 08:50:00',
            'punch_state' => 0,
            'verify_type' => 15,
            'sync_source' => 'ADMS',
        ]);

        // Lunch Out: 12:10:00 (within 11:00 - 12:30 window)
        AttendanceRawLog::query()->create([
            'biometric_pin' => self::TEST_CONTROL_NO,
            'employee_control_no' => self::TEST_CONTROL_NO,
            'punch_time' => self::TEST_DATE.' 12:10:00',
            'punch_state' => 1,
            'verify_type' => 15,
            'sync_source' => 'ADMS',
        ]);

        // Lunch In: 12:50:00 (within 12:31 - 14:00 window)
        AttendanceRawLog::query()->create([
            'biometric_pin' => self::TEST_CONTROL_NO,
            'employee_control_no' => self::TEST_CONTROL_NO,
            'punch_time' => self::TEST_DATE.' 12:50:00',
            'punch_state' => 0,
            'verify_type' => 15,
            'sync_source' => 'ADMS',
        ]);

        // Time Out: 17:05:00
        AttendanceRawLog::query()->create([
            'biometric_pin' => self::TEST_CONTROL_NO,
            'employee_control_no' => self::TEST_CONTROL_NO,
            'punch_time' => self::TEST_DATE.' 17:05:00',
            'punch_state' => 1,
            'verify_type' => 15,
            'sync_source' => 'ADMS',
        ]);

        $dtr = $service->calculateForEmployeeDate(self::TEST_CONTROL_NO, self::TEST_DATE);

        $this->assertNotNull($dtr);
        $this->assertSame('07:30:00', $dtr->am_arrival, 'Should record earliest punch (07:30) as arrival');
        $this->assertSame('12:10:00', $dtr->am_departure);
        $this->assertSame('12:50:00', $dtr->pm_arrival);
        $this->assertSame('17:05:00', $dtr->pm_departure);
        $this->assertSame(0, $dtr->late_minutes, 'Should have 0 late minutes');
        $this->assertSame(0, $dtr->undertime_minutes, 'Should have 0 undertime minutes');
    }

    public function test_dtr_smart_safeguard_pairs_lunch_punches_when_both_occur_after_twelve_thirty(): void
    {
        $service = app(DtrCalculationService::class);

        // Morning In: 07:30:00 (early, on time)
        AttendanceRawLog::query()->create([
            'biometric_pin' => self::TEST_CONTROL_NO,
            'employee_control_no' => self::TEST_CONTROL_NO,
            'punch_time' => self::TEST_DATE.' 07:30:00',
            'punch_state' => 0,
            'verify_type' => 15,
            'sync_source' => 'ADMS',
        ]);

        // Lunch Out: 12:35:00 (left late for lunch after 12:30 PM!)
        AttendanceRawLog::query()->create([
            'biometric_pin' => self::TEST_CONTROL_NO,
            'employee_control_no' => self::TEST_CONTROL_NO,
            'punch_time' => self::TEST_DATE.' 12:35:00',
            'punch_state' => 1,
            'verify_type' => 15,
            'sync_source' => 'ADMS',
        ]);

        // Lunch In: 13:00:00 (returned on time at 1:00 PM)
        AttendanceRawLog::query()->create([
            'biometric_pin' => self::TEST_CONTROL_NO,
            'employee_control_no' => self::TEST_CONTROL_NO,
            'punch_time' => self::TEST_DATE.' 13:00:00',
            'punch_state' => 0,
            'verify_type' => 15,
            'sync_source' => 'ADMS',
        ]);

        // Time Out: 17:00:00
        AttendanceRawLog::query()->create([
            'biometric_pin' => self::TEST_CONTROL_NO,
            'employee_control_no' => self::TEST_CONTROL_NO,
            'punch_time' => self::TEST_DATE.' 17:00:00',
            'punch_state' => 1,
            'verify_type' => 15,
            'sync_source' => 'ADMS',
        ]);

        $dtr = $service->calculateForEmployeeDate(self::TEST_CONTROL_NO, self::TEST_DATE);

        $this->assertNotNull($dtr);
        $this->assertSame('07:30:00', $dtr->am_arrival);
        $this->assertSame('12:35:00', $dtr->am_departure, 'Smart safeguard should slot 12:35 as Lunch-Out');
        $this->assertSame('13:00:00', $dtr->pm_arrival, 'Smart safeguard should slot 13:00 as Lunch-In');
        $this->assertSame('17:00:00', $dtr->pm_departure);
        $this->assertSame(0, $dtr->late_minutes, 'Returned at 1:00 PM, 0 late minutes');
        $this->assertSame(0, $dtr->undertime_minutes, 'Left after 12:00 PM, 0 undertime minutes');
    }

    public function test_dtr_double_tap_at_lunch_departure_boundary_prefers_twelve_oclock_punch(): void
    {
        $service = app(DtrCalculationService::class);

        // Morning In: 07:50:00
        AttendanceRawLog::query()->create([
            'biometric_pin' => self::TEST_CONTROL_NO,
            'employee_control_no' => self::TEST_CONTROL_NO,
            'punch_time' => self::TEST_DATE.' 07:50:00',
            'punch_state' => 0,
            'verify_type' => 15,
            'sync_source' => 'ADMS',
        ]);

        // Lunch Out: Tap 1 at 11:59:45 (accidental early tap)
        AttendanceRawLog::query()->create([
            'biometric_pin' => self::TEST_CONTROL_NO,
            'employee_control_no' => self::TEST_CONTROL_NO,
            'punch_time' => self::TEST_DATE.' 11:59:45',
            'punch_state' => 1,
            'verify_type' => 15,
            'sync_source' => 'ADMS',
        ]);

        // Lunch Out: Tap 2 at 12:00:10 (correction tap 25 seconds later)
        AttendanceRawLog::query()->create([
            'biometric_pin' => self::TEST_CONTROL_NO,
            'employee_control_no' => self::TEST_CONTROL_NO,
            'punch_time' => self::TEST_DATE.' 12:00:10',
            'punch_state' => 1,
            'verify_type' => 15,
            'sync_source' => 'ADMS',
        ]);

        // Lunch In: 12:55:00
        AttendanceRawLog::query()->create([
            'biometric_pin' => self::TEST_CONTROL_NO,
            'employee_control_no' => self::TEST_CONTROL_NO,
            'punch_time' => self::TEST_DATE.' 12:55:00',
            'punch_state' => 0,
            'verify_type' => 15,
            'sync_source' => 'ADMS',
        ]);

        // Afternoon Out: 17:05:00
        AttendanceRawLog::query()->create([
            'biometric_pin' => self::TEST_CONTROL_NO,
            'employee_control_no' => self::TEST_CONTROL_NO,
            'punch_time' => self::TEST_DATE.' 17:05:00',
            'punch_state' => 1,
            'verify_type' => 15,
            'sync_source' => 'ADMS',
        ]);

        $dtr = $service->calculateForEmployeeDate(self::TEST_CONTROL_NO, self::TEST_DATE);

        $this->assertNotNull($dtr);
        $this->assertSame('07:50:00', $dtr->am_arrival);
        $this->assertSame('12:00:10', $dtr->am_departure, 'Should keep 12:00:10 rather than 11:59:45 to avoid false undertime');
        $this->assertSame('12:55:00', $dtr->pm_arrival);
        $this->assertSame('17:05:00', $dtr->pm_departure);
        $this->assertSame(0, $dtr->late_minutes);
        $this->assertSame(0, $dtr->undertime_minutes, 'Employee should have 0 undertime minutes');
    }

    public function test_dtr_multiple_punches_before_twelve_thirty_slots_to_lunch_out_and_punch_at_twelve_thirty_one_slots_to_lunch_in(): void
    {
        $service = app(DtrCalculationService::class);

        // Morning In: 08:43:00
        AttendanceRawLog::query()->create([
            'biometric_pin' => self::TEST_CONTROL_NO,
            'employee_control_no' => self::TEST_CONTROL_NO,
            'punch_time' => self::TEST_DATE.' 08:43:00',
            'punch_state' => 0,
            'verify_type' => 15,
            'sync_source' => 'ADMS',
        ]);

        // Lunch Out: 12:00:00
        AttendanceRawLog::query()->create([
            'biometric_pin' => self::TEST_CONTROL_NO,
            'employee_control_no' => self::TEST_CONTROL_NO,
            'punch_time' => self::TEST_DATE.' 12:00:00',
            'punch_state' => 1,
            'verify_type' => 15,
            'sync_source' => 'ADMS',
        ]);

        // Second Lunch Out Tap: 12:08:00 (both before 12:30)
        AttendanceRawLog::query()->create([
            'biometric_pin' => self::TEST_CONTROL_NO,
            'employee_control_no' => self::TEST_CONTROL_NO,
            'punch_time' => self::TEST_DATE.' 12:08:00',
            'punch_state' => 1,
            'verify_type' => 15,
            'sync_source' => 'ADMS',
        ]);

        // Lunch In: 12:31:00 (after 12:30 PM window boundary)
        AttendanceRawLog::query()->create([
            'biometric_pin' => self::TEST_CONTROL_NO,
            'employee_control_no' => self::TEST_CONTROL_NO,
            'punch_time' => self::TEST_DATE.' 12:31:00',
            'punch_state' => 0,
            'verify_type' => 15,
            'sync_source' => 'ADMS',
        ]);

        $dtr = $service->calculateForEmployeeDate(self::TEST_CONTROL_NO, self::TEST_DATE);

        $this->assertNotNull($dtr);
        $this->assertSame('08:43:00', $dtr->am_arrival);
        $this->assertSame('12:00:00', $dtr->am_departure, 'Should record 12:00 as Lunch Out');
        $this->assertSame('12:31:00', $dtr->pm_arrival, 'Should record 12:31 as Lunch In');
        $this->assertNull($dtr->pm_departure);
    }

    public function test_dtr_manual_override_persists_and_recalculates_on_subsequent_runs(): void
    {
        $service = app(DtrCalculationService::class);

        // Raw punches with a late arrival: 08:43:00
        AttendanceRawLog::query()->create([
            'biometric_pin' => self::TEST_CONTROL_NO,
            'employee_control_no' => self::TEST_CONTROL_NO,
            'punch_time' => self::TEST_DATE.' 08:43:00',
            'punch_state' => 0,
            'verify_type' => 15,
            'sync_source' => 'ADMS',
        ]);
        AttendanceRawLog::query()->create([
            'biometric_pin' => self::TEST_CONTROL_NO,
            'employee_control_no' => self::TEST_CONTROL_NO,
            'punch_time' => self::TEST_DATE.' 12:00:00',
            'punch_state' => 1,
            'verify_type' => 15,
            'sync_source' => 'ADMS',
        ]);
        AttendanceRawLog::query()->create([
            'biometric_pin' => self::TEST_CONTROL_NO,
            'employee_control_no' => self::TEST_CONTROL_NO,
            'punch_time' => self::TEST_DATE.' 12:32:00',
            'punch_state' => 0,
            'verify_type' => 15,
            'sync_source' => 'ADMS',
        ]);

        // First calculation: yields 43 mins late
        $dtr = $service->calculateForEmployeeDate(self::TEST_CONTROL_NO, self::TEST_DATE);
        $this->assertSame('08:43:00', $dtr->am_arrival);
        $this->assertSame(43, $dtr->late_minutes);
        $this->assertFalse((bool) $dtr->is_adjusted);

        // Admin overrides times: on-time arrival, departure at 5pm, plus 5 hours overtime
        $dtr->am_arrival = '07:43:00';
        $dtr->am_departure = '12:00:00';
        $dtr->pm_arrival = '12:32:00';
        $dtr->pm_departure = '17:00:00';
        $dtr->ot_arrival = '17:00:00';
        $dtr->ot_departure = '22:00:00';
        $dtr->is_adjusted = true;
        $dtr->adjustment_reason = 'MANUAL_OVERRIDE';
        $dtr->adjustment_remarks = 'Manual override by admin';
        $dtr->save();

        // Subsequent calculation (e.g. range refresh or fetch DTR) must preserve the overrides
        $refreshed = $service->calculateForEmployeeDate(self::TEST_CONTROL_NO, self::TEST_DATE);

        $this->assertNotNull($refreshed);
        $this->assertTrue((bool) $refreshed->is_adjusted);
        $this->assertSame('MANUAL_OVERRIDE', $refreshed->adjustment_reason);
        $this->assertSame('07:43:00', $refreshed->am_arrival, 'Arrival must remain the overridden 07:43:00');
        $this->assertSame('12:00:00', $refreshed->am_departure);
        $this->assertSame('12:32:00', $refreshed->pm_arrival);
        $this->assertSame('17:00:00', $refreshed->pm_departure, 'Departure must remain the overridden 17:00:00');
        $this->assertSame('17:00:00', $refreshed->ot_arrival);
        $this->assertSame('22:00:00', $refreshed->ot_departure);
        $this->assertSame(0, $refreshed->late_minutes, 'Late minutes must recalculate to 0 based on 07:43 arrival');
        $this->assertSame(0, $refreshed->undertime_minutes);
        $this->assertSame(300, $refreshed->overtime_minutes, 'Overtime minutes must be 300 (5 hours)');
        $this->assertSame(8.0, (float) $refreshed->rendered_hours);
        $this->assertSame(DailyTimeRecord::STATUS_PRESENT, $refreshed->status);
    }
}
