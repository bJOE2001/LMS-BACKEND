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
        $this->assertSame($engineeringDeviceSn, $dtr->am_arrival_device_sn);
        $this->assertSame('17:05:00', $dtr->pm_departure);
        $this->assertSame($itDeviceSn, $dtr->pm_departure_device_sn);
    }

    public function test_universal_broadcast_queues_commands_for_all_devices(): void
    {
        $admsService = app(\App\Services\Attendance\ZkAdmsService::class);
        $deviceSn1 = 'TEST-DEV-001';
        $deviceSn2 = 'TEST-DEV-002';

        try {
            // Clean up any test devices and commands
            \App\Models\BiometricDevice::query()->whereIn('serial_number', [$deviceSn1, $deviceSn2])->delete();
            \App\Models\BiometricDeviceCommand::query()->where('employee_control_no', self::TEST_CONTROL_NO)->delete();

            // Create two active authorized devices
            \App\Models\BiometricDevice::query()->create([
                'serial_number' => $deviceSn1,
                'device_name' => 'IT Office MB360',
                'is_active' => true,
                'status' => 'ONLINE',
            ]);
            \App\Models\BiometricDevice::query()->create([
                'serial_number' => $deviceSn2,
                'device_name' => 'Engineering Office MB360',
                'is_active' => true,
                'status' => 'ONLINE',
            ]);

            $totalActiveDevices = \App\Models\BiometricDevice::query()->where('is_active', true)->where('status', '!=', 'BLOCKED')->count();

            // Broadcast employee
            $queued = $admsService->broadcastEmployeeToAllDevices(self::TEST_CONTROL_NO, 'Juan Dela Cruz');

            $this->assertSame($totalActiveDevices, $queued, 'Should queue commands for all active devices');

            // Verify commands exist for our test devices
            $commands = \App\Models\BiometricDeviceCommand::query()
                ->where('employee_control_no', self::TEST_CONTROL_NO)
                ->whereIn('device_serial_number', [$deviceSn1, $deviceSn2])
                ->get();

            $this->assertCount(2, $commands);
            foreach ($commands as $cmd) {
                $this->assertSame(\App\Models\BiometricDeviceCommand::STATUS_PENDING, $cmd->status);
                $this->assertStringContainsString('DATA USER PIN='.self::TEST_CONTROL_NO, $cmd->command_payload);
            }
        } finally {
            \App\Models\BiometricDevice::query()->whereIn('serial_number', [$deviceSn1, $deviceSn2])->delete();
            \App\Models\BiometricDeviceCommand::query()->where('employee_control_no', self::TEST_CONTROL_NO)->delete();
        }
    }
}
