<?php

namespace Tests\Feature;

use App\Models\Attendance\AttendanceRawLog;
use App\Models\Attendance\BiometricDevice;
use App\Models\Attendance\BiometricEnrollment;
use App\Models\Attendance\DailyTimeRecord;
use App\Models\Attendance\Holiday;
use App\Models\Department;
use App\Models\EmployeeDepartmentAssignment;
use App\Models\HrisEmployee;
use App\Models\Leave\LeaveApplication;
use App\Models\Leave\LeaveType;
use App\Models\WorkScheduleSetting;
use App\Services\Attendance\AttendanceBatchIngestService;
use App\Services\Attendance\DtrCalculationService;
use App\Services\Attendance\LanBioPunchSyncService;
use App\Services\Attendance\LanBioTemplateSyncService;
use App\Services\Attendance\NativeAdmsService;
use App\Services\Attendance\ZkSocketClient;
use App\Services\WorkScheduleService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Mockery;
use ReflectionMethod;
use Tests\TestCase;

class AttendanceBatchSyncTest extends TestCase
{
    private Department $chrmoDepartment;

    private Department $cictmoDepartment;

    protected function setUp(): void
    {
        parent::setUp();

        Cache::flush();
        HrisEmployee::flushCache();
        app(WorkScheduleService::class)->clearScheduleCache();

        Config::set('database.default', 'sqlsrv');
        Config::set('database.connections.hr', [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
        ]);
        Config::set('database.connections.sqlsrv', [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
        ]);
        Config::set('database.connections.bio', [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
        ]);

        DB::purge('hr');
        DB::purge('sqlsrv');
        DB::purge('bio');

        $this->createSchemas();
        $this->seedFixtures();
    }

    public function test_batch_ingest_deduplicates_in_batch_and_existing_logs_and_canonicalizes_pins(): void
    {
        AttendanceRawLog::query()->create([
            'device_serial_number' => 'SN-CHRMO-01',
            'biometric_pin' => '11790',
            'employee_control_no' => '011790',
            'punch_time' => '2026-10-15 07:55:00',
            'punch_state' => AttendanceRawLog::STATE_CHECK_IN,
            'verify_type' => AttendanceRawLog::VERIFY_FINGERPRINT,
            'sync_source' => 'ADMS',
        ]);

        $service = app(AttendanceBatchIngestService::class);
        $this->cacheHrisRows([], ['11790', '011790']);

        $result = $service->ingestBatch([
            ['pin' => '11790', 'punch_time' => '2026-10-15 07:55:00', 'punch_state' => 0, 'verify_type' => 1],
            ['pin' => '11790', 'punch_time' => '2026-10-15 12:05:00', 'punch_state' => 1, 'verify_type' => 1],
            ['pin' => '11790', 'punch_time' => '2026-10-15 12:05:00', 'punch_state' => 1, 'verify_type' => 1],
            ['pin' => '011790', 'punch_time' => '2026-10-15 17:02:00', 'punch_state' => 1, 'verify_type' => 1],
        ], 'SN-CHRMO-01', 'ADMS');

        $this->assertSame(2, $result['imported']);
        $this->assertSame(2, $result['duplicates']);
        $this->assertArrayHasKey('011790', $result['affected_dates']);
        $this->assertTrue($result['affected_dates']['011790']['2026-10-15']);

        $logs = AttendanceRawLog::query()->orderBy('punch_time')->get();
        $this->assertCount(3, $logs);
        $this->assertSame(['011790', '011790', '011790'], $logs->pluck('employee_control_no')->all());
    }

    public function test_lan_sync_confirms_pending_enrollment_calculates_dtr_and_defaults_sync_templates_to_false(): void
    {
        $method = new ReflectionMethod(LanBioPunchSyncService::class, 'syncDevice');
        $params = $method->getParameters();
        $this->assertSame('syncTemplates', $params[2]->getName());
        $this->assertFalse($params[2]->getDefaultValue());

        $device = BiometricDevice::query()->where('serial_number', 'SN-CICTMO-01')->firstOrFail();

        BiometricEnrollment::query()->create([
            'employee_control_no' => '00123',
            'employee_name' => 'Dela Cruz, Juan',
            'status' => BiometricEnrollment::STATUS_PENDING_ENROLLMENT,
        ]);

        $zkMock = Mockery::mock(ZkSocketClient::class);
        $zkMock->shouldReceive('connect')->once()->andReturn(true);
        $zkMock->shouldReceive('getAttendanceLogs')->once()->andReturn([
            ['pin' => '123', 'time' => '2026-10-15 08:10:00', 'state' => 0, 'type' => 1],
            ['pin' => '123', 'time' => '2026-10-15 17:05:00', 'state' => 1, 'type' => 1],
        ]);
        $zkMock->shouldReceive('disconnect')->once()->andReturn(true);

        $templateMock = Mockery::mock(LanBioTemplateSyncService::class);
        $templateMock->shouldNotReceive('pullTemplatesFromDevice');

        $syncService = new LanBioPunchSyncService(
            app(DtrCalculationService::class),
            $templateMock,
            app(AttendanceBatchIngestService::class)
        );
        $this->cacheHrisRows([], ['123']);

        $result = $syncService->syncDevice($device, $zkMock);

        $this->assertTrue($result['success']);
        $this->assertSame(2, $result['imported']);
        $this->assertSame(0, $result['duplicates']);

        $enrollment = BiometricEnrollment::query()->where('employee_control_no', '00123')->firstOrFail();
        $this->assertSame(BiometricEnrollment::STATUS_REGISTERED, $enrollment->status);
        $this->assertSame('SN-CICTMO-01', $enrollment->enrollment_device_sn);

        $dtr = DailyTimeRecord::query()
            ->where('employee_control_no', '00123')
            ->where('record_date', '2026-10-15')
            ->firstOrFail();
        $this->assertSame($this->chrmoDepartment->id, $dtr->department_id);
        $this->assertSame('08:10:00', $dtr->am_arrival);
        $this->assertSame('17:05:00', $dtr->pm_departure);
        $this->assertSame(10, $dtr->late_minutes);
        $this->assertSame(DailyTimeRecord::STATUS_PRESENT, $dtr->status);
        $this->assertNotNull(AttendanceRawLog::query()->first()->processed_at);
    }

    public function test_adms_push_processes_large_batch_spanning_chunks_and_preserves_adjustments_holidays_and_leaves(): void
    {
        // Seed extra HRIS employees to cross >200 lookup chunk, >150 insert chunk, and >75 DTR insert chunk
        $rawPins = ['123', '456'];
        $extraHris = [];
        $attlogLines = [
            "123\t2026-10-15 08:10:00\t0\t1",
            "123\t2026-10-15 17:00:00\t1\t1",
            "456\t2026-10-14 07:55:00\t0\t1",
            "456\t2026-10-14 12:01:00\t1\t1",
            "456\t2026-10-15 09:30:00\t0\t1",
            "456\t2026-10-16 08:00:00\t0\t1",
        ];

        for ($i = 1000; $i < 1220; $i++) {
            $ctrl = str_pad((string) $i, 6, '0', STR_PAD_LEFT);
            $rawPins[] = (string) $i;
            $extraHris[] = [
                'control_no' => $ctrl,
                'surname' => "Employee{$i}",
                'firstname' => 'Batch',
                'middlename' => null,
                'designation' => 'CLERK',
                'office' => $this->chrmoDepartment->name,
                'officeAcronym' => 'CHRMO',
                'status' => 'PERMANENT',
            ];
            $attlogLines[] = "{$i}\t2026-10-15 07:55:00\t0\t1";
        }

        $this->cacheHrisRows($extraHris, $rawPins);
        $canonicalLookupPins = array_map(
            static fn (int $i): string => str_pad((string) $i, 6, '0', STR_PAD_LEFT),
            range(1000, 1219)
        );
        $this->cacheHrisRows($extraHris, array_merge(['00123', '00456'], $canonicalLookupPins));

        // Pre-existing manual OB adjustment for 00456 on 2026-10-15
        DailyTimeRecord::query()->create([
            'employee_control_no' => '00456',
            'department_id' => $this->cictmoDepartment->id,
            'record_date' => '2026-10-15',
            'day_of_week' => 'Thursday',
            'am_arrival' => '08:00:00',
            'am_departure' => '12:00:00',
            'pm_arrival' => '13:00:00',
            'pm_departure' => '17:00:00',
            'late_minutes' => 0,
            'undertime_minutes' => 0,
            'overtime_minutes' => 0,
            'rendered_hours' => 8.00,
            'status' => DailyTimeRecord::STATUS_OFFICIAL_BUSINESS,
            'is_adjusted' => true,
            'adjustment_reason' => DailyTimeRecord::STATUS_OFFICIAL_BUSINESS,
            'adjustment_remarks' => 'Field assignment',
        ]);

        // Approved PM half-day Sick Leave for 00456 on 2026-10-14
        $leaveType = LeaveType::query()->create(['name' => 'Sick Leave']);
        LeaveApplication::query()->create([
            'employee_control_no' => '00456',
            'leave_type_id' => $leaveType->id,
            'start_date' => '2026-10-14',
            'end_date' => '2026-10-14',
            'total_days' => 0.5,
            'status' => 'APPROVED',
            'selected_dates' => ['2026-10-14'],
            'selected_date_coverage' => ['2026-10-14' => 'half'],
            'selected_date_half_day_portion' => ['2026-10-14' => 'pm'],
        ]);

        // Active holiday on 2026-10-16
        Holiday::query()->create([
            'holiday_date' => '2026-10-16',
            'name' => 'Founding Anniversary',
            'type' => 'SPECIAL',
            'is_recurring' => false,
            'is_active' => true,
        ]);

        $admsService = app(NativeAdmsService::class);
        $rawBody = implode("\n", $attlogLines);

        $firstPush = $admsService->handleDataPush(
            Request::create('/iclock/cdata?SN=SN-CHRMO-01&table=ATTLOG', 'POST', [], [], [], [], $rawBody),
            'SN-CHRMO-01'
        );
        $this->assertTrue($firstPush['success']);
        $this->assertSame(count($attlogLines), $firstPush['imported']);
        $this->assertSame(0, $firstPush['duplicates']);

        // Second identical push must detect 100% duplicates cleanly
        $secondPush = $admsService->handleDataPush(
            Request::create('/iclock/cdata?SN=SN-CHRMO-01&table=ATTLOG', 'POST', [], [], [], [], $rawBody),
            'SN-CHRMO-01'
        );
        $this->assertTrue($secondPush['success']);
        $this->assertSame(0, $secondPush['imported']);
        $this->assertSame(count($attlogLines), $secondPush['duplicates']);

        $dtr00123 = DailyTimeRecord::query()
            ->where('employee_control_no', '00123')
            ->where('record_date', '2026-10-15')
            ->firstOrFail();
        $this->assertSame(10, $dtr00123->late_minutes);
        $this->assertSame(DailyTimeRecord::STATUS_PRESENT, $dtr00123->status);

        $dtr00456Adjusted = DailyTimeRecord::query()
            ->where('employee_control_no', '00456')
            ->where('record_date', '2026-10-15')
            ->firstOrFail();
        $this->assertTrue($dtr00456Adjusted->is_adjusted);
        $this->assertSame(DailyTimeRecord::STATUS_OFFICIAL_BUSINESS, $dtr00456Adjusted->status);
        $this->assertSame('08:00:00', $dtr00456Adjusted->am_arrival);

        $dtr00456Holiday = DailyTimeRecord::query()
            ->where('employee_control_no', '00456')
            ->where('record_date', '2026-10-16')
            ->firstOrFail();
        $this->assertSame(DailyTimeRecord::STATUS_HOLIDAY, $dtr00456Holiday->status);
        $this->assertSame('Founding Anniversary', $dtr00456Holiday->adjustment_remarks);

        $dtr00456Leave = DailyTimeRecord::query()
            ->where('employee_control_no', '00456')
            ->where('record_date', '2026-10-14')
            ->firstOrFail();
        $this->assertSame(DailyTimeRecord::STATUS_ON_LEAVE_PM, $dtr00456Leave->status);
        $this->assertSame('SL', $dtr00456Leave->leave_type_code);

        $this->assertGreaterThanOrEqual(220, DailyTimeRecord::query()->where('record_date', '2026-10-15')->count());
        $this->assertSame(0, AttendanceRawLog::query()->whereNull('processed_at')->count());
    }

    public function test_work_schedule_service_memoizes_default_setting_across_calls(): void
    {
        $service = app(WorkScheduleService::class);
        $service->clearScheduleCache();

        DB::connection('sqlsrv')->enableQueryLog();
        for ($i = 0; $i < 10; $i++) {
            $service->resolveScheduleForEmployee('00123');
        }
        $queries = DB::connection('sqlsrv')->getQueryLog();
        DB::connection('sqlsrv')->disableQueryLog();

        $defaultQueries = array_filter(
            $queries,
            static fn (array $entry): bool => str_contains($entry['query'] ?? '', 'tblWorkScheduleSettings')
        );

        $this->assertLessThanOrEqual(3, count($defaultQueries));
    }

    public function test_four_thousand_punch_morning_burst_stays_within_query_and_time_budgets(): void
    {
        $totalEmployees = 4000;
        $rawPins = [];
        $extraHris = [];
        $attlogLines = [];
        $deptAssignments = [];

        for ($i = 1000; $i < 1000 + $totalEmployees; $i++) {
            $ctrl = str_pad((string) $i, 6, '0', STR_PAD_LEFT);
            $rawPins[] = (string) $i;
            $extraHris[] = [
                'control_no' => $ctrl,
                'surname' => "Employee{$i}",
                'firstname' => 'Stress',
                'middlename' => null,
                'designation' => 'CLERK',
                'office' => $this->chrmoDepartment->name,
                'officeAcronym' => 'CHRMO',
                'status' => 'PERMANENT',
            ];
            $attlogLines[] = "{$i}\t2026-10-15 07:58:00\t0\t1";
            $deptAssignments[] = [
                'employee_control_no' => $ctrl,
                'department_id' => $this->chrmoDepartment->id,
            ];
        }

        foreach (array_chunk($deptAssignments, 200) as $chunk) {
            EmployeeDepartmentAssignment::query()->insert($chunk);
        }

        foreach (array_chunk($rawPins, 400) as $pinChunk) {
            $this->cacheHrisRows($extraHris, $pinChunk);
        }

        $admsService = app(NativeAdmsService::class);
        $rawBody = implode("\n", $attlogLines);
        $request = Request::create('/iclock/cdata?SN=SN-CHRMO-01&table=ATTLOG', 'POST', [], [], [], [], $rawBody);

        DB::connection('bio')->flushQueryLog();
        DB::connection('sqlsrv')->flushQueryLog();
        DB::connection('bio')->enableQueryLog();
        DB::connection('sqlsrv')->enableQueryLog();

        $startNs = hrtime(true);
        $result = $admsService->handleDataPush($request, 'SN-CHRMO-01');
        $elapsedMs = (hrtime(true) - $startNs) / 1_000_000;

        $bioQueries = count(DB::connection('bio')->getQueryLog());
        $sqlsrvQueries = count(DB::connection('sqlsrv')->getQueryLog());
        DB::connection('bio')->disableQueryLog();
        DB::connection('sqlsrv')->disableQueryLog();

        $totalQueries = $bioQueries + $sqlsrvQueries;
        $peakMb = round(memory_get_peak_usage(true) / 1048576, 2);

        fwrite(
            STDOUT,
            sprintf(
                "\n[4K Punch Benchmark] time=%.1fms bio_queries=%d sqlsrv_queries=%d total_queries=%d peak_mem=%.2fMB\n",
                $elapsedMs,
                $bioQueries,
                $sqlsrvQueries,
                $totalQueries,
                $peakMb
            )
        );

        $this->assertTrue($result['success']);
        $this->assertSame($totalEmployees, $result['imported']);
        $this->assertSame(0, $result['duplicates']);
        $this->assertSame($totalEmployees, DailyTimeRecord::query()->where('record_date', '2026-10-15')->count());
        $this->assertSame(0, AttendanceRawLog::query()->whereNull('processed_at')->count());
        $this->assertLessThan(350, $totalQueries);
        $this->assertLessThan(25000, $elapsedMs);
    }

    private function createSchemas(): void
    {
        Schema::connection('sqlsrv')->create('tblDepartments', function (Blueprint $table): void {
            $table->increments('id');
            $table->string('name', 255);
            $table->unsignedInteger('main_department_id')->nullable();
            $table->boolean('is_inactive')->default(false);
            $table->timestamps();
        });

        Schema::connection('sqlsrv')->create('tblEmployeeDepartmentAssignments', function (Blueprint $table): void {
            $table->increments('id');
            $table->string('employee_control_no', 64);
            $table->unsignedInteger('department_id');
            $table->unsignedInteger('assigned_by_department_admin_id')->nullable();
            $table->timestamp('assigned_at')->nullable();
            $table->string('surname', 100)->nullable();
            $table->string('firstname', 100)->nullable();
            $table->string('middlename', 100)->nullable();
            $table->string('department', 50)->nullable();
            $table->timestamps();
        });

        Schema::connection('sqlsrv')->create('tblWorkScheduleSettings', function (Blueprint $table): void {
            $table->increments('id');
            $table->string('setting_key', 64)->unique();
            $table->string('work_start_time', 10);
            $table->string('work_end_time', 10);
            $table->string('break_start_time', 10)->nullable();
            $table->string('break_end_time', 10)->nullable();
            $table->decimal('working_hours_per_day', 5, 2);
            $table->decimal('whole_day_leave_deduction', 6, 3);
            $table->decimal('half_day_leave_deduction', 6, 3);
            $table->text('notes')->nullable();
            $table->unsignedInteger('updated_by_hr_account_id')->nullable();
            $table->timestamps();
        });

        Schema::connection('sqlsrv')->create('tblEmployeeWorkScheduleOverrides', function (Blueprint $table): void {
            $table->increments('id');
            $table->string('employee_control_no', 64);
            $table->string('employee_name', 150)->nullable();
            $table->string('office', 150)->nullable();
            $table->string('designation', 150)->nullable();
            $table->string('status', 50)->nullable();
            $table->string('work_start_time', 10);
            $table->string('work_end_time', 10);
            $table->string('break_start_time', 10)->nullable();
            $table->string('break_end_time', 10)->nullable();
            $table->decimal('working_hours_per_day', 5, 2);
            $table->decimal('whole_day_leave_deduction', 6, 3);
            $table->decimal('half_day_leave_deduction', 6, 3);
            $table->text('notes')->nullable();
            $table->boolean('is_active')->default(true);
            $table->unsignedInteger('updated_by_hr_account_id')->nullable();
            $table->timestamps();
        });

        Schema::connection('sqlsrv')->create('tblLeaveTypes', function (Blueprint $table): void {
            $table->increments('id');
            $table->string('name', 150);
            $table->timestamps();
        });

        Schema::connection('sqlsrv')->create('tblLeaveApplications', function (Blueprint $table): void {
            $table->increments('id');
            $table->string('employee_control_no', 64);
            $table->string('employee_name', 150)->nullable();
            $table->unsignedInteger('leave_type_id');
            $table->date('start_date');
            $table->date('end_date');
            $table->decimal('total_days', 5, 2)->default(1);
            $table->string('status', 30)->default('PENDING');
            $table->string('pay_mode', 10)->default('WP');
            $table->boolean('is_monetization')->default(false);
            $table->json('monetization_leave_credits')->nullable();
            $table->decimal('deductible_days', 5, 2)->default(0);
            $table->decimal('without_pay_days', 5, 2)->default(0);
            $table->decimal('cto_deducted_hours', 6, 2)->nullable();
            $table->string('commutation', 30)->nullable();
            $table->json('selected_dates')->nullable();
            $table->json('selected_date_pay_status')->nullable();
            $table->json('selected_date_coverage')->nullable();
            $table->json('selected_date_half_day_portion')->nullable();
            $table->timestamps();
        });

        Schema::connection('bio')->create('tblBiometricDevices', function (Blueprint $table): void {
            $table->increments('id');
            $table->string('device_name', 100);
            $table->string('serial_number', 50)->unique();
            $table->string('communication_mode', 20)->default('ADMS');
            $table->string('ip_address', 45)->nullable();
            $table->integer('port')->default(4370);
            $table->integer('comm_key')->default(0);
            $table->unsignedBigInteger('department_id')->nullable();
            $table->string('department_name', 150)->nullable();
            $table->string('model_name', 50)->nullable();
            $table->string('status', 20)->default('OFFLINE');
            $table->timestamp('last_heartbeat_at')->nullable();
            $table->timestamp('last_sync_at')->nullable();
            $table->integer('device_log_count')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::connection('bio')->create('tblBiometricEnrollments', function (Blueprint $table): void {
            $table->increments('id');
            $table->string('employee_control_no', 64)->unique();
            $table->string('employee_name', 150)->nullable();
            $table->string('status', 30)->default('NOT_REGISTERED');
            $table->string('enrollment_device_sn', 50)->nullable();
            $table->timestamp('enrolled_at')->nullable();
            $table->unsignedBigInteger('enrolled_by_user_id')->nullable();
            $table->json('synced_devices')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();
        });

        Schema::connection('bio')->create('tblAttendanceLogs', function (Blueprint $table): void {
            $table->increments('id');
            $table->string('device_serial_number', 50)->nullable();
            $table->string('biometric_pin', 64);
            $table->string('employee_control_no', 64)->nullable();
            $table->dateTime('punch_time');
            $table->smallInteger('punch_state')->default(0);
            $table->smallInteger('verify_type')->default(15);
            $table->string('sync_source', 20)->default('ADMS');
            $table->string('raw_payload', 255)->nullable();
            $table->timestamp('processed_at')->nullable();
            $table->timestamp('voided_at')->nullable();
            $table->unsignedBigInteger('voided_by_hr_account_id')->nullable();
            $table->string('void_reason', 255)->nullable();
            $table->timestamps();

            $table->unique(['biometric_pin', 'punch_time'], 'UQ_tblAttendanceLogs_pin_punch_time');
        });

        Schema::connection('bio')->create('tblDailyTimeRecords', function (Blueprint $table): void {
            $table->increments('id');
            $table->string('employee_control_no', 64);
            $table->unsignedBigInteger('department_id')->nullable();
            $table->date('record_date');
            $table->string('day_of_week', 15)->nullable();
            $table->string('am_arrival', 20)->nullable();
            $table->string('am_departure', 20)->nullable();
            $table->string('pm_arrival', 20)->nullable();
            $table->string('pm_departure', 20)->nullable();
            $table->string('ot_arrival', 20)->nullable();
            $table->string('ot_departure', 20)->nullable();
            $table->integer('late_minutes')->default(0);
            $table->integer('undertime_minutes')->default(0);
            $table->integer('overtime_minutes')->default(0);
            $table->decimal('rendered_hours', 5, 2)->default(0.00);
            $table->string('status', 30)->default('PRESENT');
            $table->string('leave_type_code', 20)->nullable();
            $table->unsignedBigInteger('leave_application_id')->nullable();
            $table->boolean('is_adjusted')->default(false);
            $table->string('adjustment_reason', 50)->nullable();
            $table->text('adjustment_remarks')->nullable();
            $table->unsignedBigInteger('adjusted_by_admin_id')->nullable();
            $table->string('adjusted_slots', 150)->nullable();
            $table->timestamps();

            $table->unique(['employee_control_no', 'record_date'], 'UQ_tblDailyTimeRecords_control_no_date');
        });

        Schema::connection('bio')->create('tblHolidays', function (Blueprint $table): void {
            $table->increments('id');
            $table->date('holiday_date');
            $table->string('name', 150);
            $table->string('type', 30)->default('REGULAR');
            $table->boolean('is_recurring')->default(false);
            $table->boolean('is_active')->default(true);
            $table->text('description')->nullable();
            $table->timestamps();
        });
    }

    private function seedFixtures(): void
    {
        $this->chrmoDepartment = Department::query()->create([
            'name' => 'Office of the City Human Resource Management Officer',
            'is_inactive' => false,
        ]);
        $this->cictmoDepartment = Department::query()->create([
            'name' => 'City Information and Communications Technology Management Office',
            'is_inactive' => false,
        ]);

        WorkScheduleSetting::query()->create([
            'setting_key' => WorkScheduleSetting::GLOBAL_SETTING_KEY,
            'work_start_time' => '08:00',
            'work_end_time' => '17:00',
            'break_start_time' => '12:00',
            'break_end_time' => '13:00',
            'working_hours_per_day' => 8.0,
            'whole_day_leave_deduction' => 1.0,
            'half_day_leave_deduction' => 0.5,
        ]);

        EmployeeDepartmentAssignment::query()->create([
            'employee_control_no' => '00123',
            'department_id' => $this->chrmoDepartment->id,
        ]);
        EmployeeDepartmentAssignment::query()->create([
            'employee_control_no' => '00456',
            'department_id' => $this->cictmoDepartment->id,
        ]);

        BiometricDevice::query()->create([
            'device_name' => 'CHRMO Main Terminal',
            'serial_number' => 'SN-CHRMO-01',
            'communication_mode' => 'ADMS',
            'department_id' => $this->chrmoDepartment->id,
            'department_name' => $this->chrmoDepartment->name,
            'is_active' => true,
        ]);
        BiometricDevice::query()->create([
            'device_name' => 'CICTMO Lobby',
            'serial_number' => 'SN-CICTMO-01',
            'communication_mode' => 'LAN_POLL',
            'ip_address' => '192.168.10.25',
            'port' => 4370,
            'department_id' => $this->cictmoDepartment->id,
            'department_name' => $this->cictmoDepartment->name,
            'is_active' => true,
        ]);

        $this->cacheHrisRows([], ['00123', '123', '000123', '00456', '456', '000456', '011790', '11790']);
    }

    /**
     * @param  array<int, array<string, mixed>>  $extraRows
     * @param  array<int, string>  $lookupValues
     */
    private function cacheHrisRows(array $extraRows, array $lookupValues): void
    {
        $baseRows = [
            [
                'control_no' => '00123',
                'surname' => 'Dela Cruz',
                'firstname' => 'Juan',
                'middlename' => 'Santos',
                'designation' => 'ADMINISTRATIVE OFFICER IV',
                'office' => $this->chrmoDepartment->name,
                'officeAcronym' => 'CHRMO',
                'status' => 'PERMANENT',
            ],
            [
                'control_no' => '00456',
                'surname' => 'Reyes',
                'firstname' => 'Maria',
                'middlename' => 'Clara',
                'designation' => 'INFORMATION SYSTEMS ANALYST II',
                'office' => $this->cictmoDepartment->name,
                'officeAcronym' => 'CICTMO',
                'status' => 'PERMANENT',
            ],
            [
                'control_no' => '011790',
                'surname' => 'Garcia',
                'firstname' => 'Elena',
                'middlename' => 'Ramos',
                'designation' => 'HR ASSISTANT',
                'office' => $this->chrmoDepartment->name,
                'officeAcronym' => 'CHRMO',
                'status' => 'PERMANENT',
            ],
        ];

        $allRows = array_merge($baseRows, $extraRows);
        $version = (string) Cache::get('hris_employees.cache_version', 'v4');
        Cache::put("hris_employees.v5.{$version}.all.all", $allRows, now()->addMinutes(5));
        Cache::put("hris_employees.v5.{$version}.active.all", $allRows, now()->addMinutes(5));

        $expandedValues = collect($lookupValues)
            ->flatMap(static function (string $value): array {
                $raw = trim($value);
                $unpadded = ltrim($raw, '0') ?: '0';

                return [$raw, $unpadded, str_pad($unpadded, max(6, strlen($raw)), '0', STR_PAD_LEFT)];
            })
            ->unique()
            ->sort()
            ->values()
            ->all();

        $hash = md5(implode('|', $expandedValues));
        Cache::put("hris_employees.v5.{$version}.directory_lookup.{$hash}.active", $allRows, now()->addMinutes(5));
        Cache::put("hris_employees.v5.{$version}.directory_lookup.{$hash}.all", $allRows, now()->addMinutes(5));
    }
}
