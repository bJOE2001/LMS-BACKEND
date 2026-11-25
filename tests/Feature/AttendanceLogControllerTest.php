<?php

namespace Tests\Feature;

use App\Models\Attendance\AttendanceRawLog;
use App\Models\Attendance\BiometricDevice;
use App\Models\Attendance\DailyTimeRecord;
use App\Models\Department;
use App\Models\DepartmentAdmin;
use App\Models\HRAccount;
use App\Models\HrisEmployee;
use App\Models\HrisOffice;
use App\Models\HRModulePermission;
use App\Models\WorkScheduleSetting;
use App\Services\Attendance\AttendanceBatchIngestService;
use App\Services\Attendance\DtrCalculationService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class AttendanceLogControllerTest extends TestCase
{
    private HRAccount $authorizedHrAccount;

    private HRAccount $restrictedHrAccount;

    private Department $chrmoDepartment;

    private Department $cictmoDepartment;

    protected function setUp(): void
    {
        parent::setUp();

        Cache::flush();
        HrisOffice::flushCache();
        HrisEmployee::flushCache();

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

    public function test_it_requires_authentication_and_hr_employee_management_permission(): void
    {
        $this->getJson('/api/hr/attendance-logs')
            ->assertStatus(401);

        $departmentAdmin = DepartmentAdmin::query()->create([
            'department_id' => $this->chrmoDepartment->id,
            'full_name' => 'Dept Admin',
            'username' => 'dept_admin',
            'password' => 'secret',
            'must_change_password' => false,
        ]);

        Sanctum::actingAs($departmentAdmin);
        $this->getJson('/api/hr/attendance-logs')
            ->assertStatus(403);

        Sanctum::actingAs($this->restrictedHrAccount);
        $this->getJson('/api/hr/attendance-logs')
            ->assertStatus(403);
    }

    public function test_it_lists_enriched_raw_attendance_logs_with_hris_office_acronyms_and_employee_names(): void
    {
        Sanctum::actingAs($this->authorizedHrAccount);

        $response = $this->getJson('/api/hr/attendance-logs')
            ->assertOk()
            ->assertJsonStructure([
                'logs' => [[
                    'id',
                    'punch_time',
                    'employee_control_no',
                    'biometric_pin',
                    'employee_name',
                    'designation',
                    'device_serial_number',
                    'device_name',
                    'department_id',
                    'office_name',
                    'office_acronym',
                    'punch_state',
                    'verify_type',
                    'verify_type_label',
                    'sync_source',
                    'processed_at',
                ]],
                'total',
                'current_page',
                'last_page',
                'per_page',
                'filters' => [
                    'devices',
                    'offices',
                ],
            ]);

        $response->assertJsonPath('total', 3);

        $firstLog = $response->json('logs.0');
        $this->assertSame('00456', $firstLog['employee_control_no']);
        $this->assertSame('Maria C. Reyes', $firstLog['employee_name']);
        $this->assertSame('INFORMATION SYSTEMS ANALYST II', $firstLog['designation']);
        $this->assertSame('SN-CICTMO-01', $firstLog['device_serial_number']);
        $this->assertSame('CICTMO Lobby', $firstLog['device_name']);
        $this->assertSame('CICTMO', $firstLog['office_acronym']);
        $this->assertSame('Face', $firstLog['verify_type_label']);
        $this->assertSame('USB_IMPORT', $firstLog['sync_source']);

        $this->assertCount(2, $response->json('filters.devices'));
        $this->assertCount(2, $response->json('filters.offices'));
    }

    public function test_it_filters_by_search_device_office_sync_source_state_verify_processed_and_dates(): void
    {
        Sanctum::actingAs($this->authorizedHrAccount);

        // Search by HRIS employee name
        $this->getJson('/api/hr/attendance-logs?search=Reyes')
            ->assertOk()
            ->assertJsonPath('total', 1)
            ->assertJsonPath('logs.0.employee_control_no', '00456');

        // Search by HRIS office acronym resolved from yOffice
        $this->getJson('/api/hr/attendance-logs?search=CHRMO')
            ->assertOk()
            ->assertJsonPath('total', 2);

        // Filter by office department_id & sync_source
        $this->getJson('/api/hr/attendance-logs?department_id='.$this->chrmoDepartment->id.'&sync_source=LAN_POLL')
            ->assertOk()
            ->assertJsonPath('total', 1)
            ->assertJsonPath('logs.0.biometric_pin', '999');

        // Filter by punch_state & verify_type
        $this->getJson('/api/hr/attendance-logs?punch_state=255&verify_type=4')
            ->assertOk()
            ->assertJsonPath('total', 1)
            ->assertJsonPath('logs.0.biometric_pin', '999');

        // Filter by punch_state & verify_type
        $this->getJson('/api/hr/attendance-logs?punch_state=0&verify_type=1')
            ->assertOk()
            ->assertJsonPath('total', 1)
            ->assertJsonPath('logs.0.employee_control_no', '00123');

        // Filter by processed status
        $this->getJson('/api/hr/attendance-logs?processed=unprocessed')
            ->assertOk()
            ->assertJsonPath('total', 1)
            ->assertJsonPath('logs.0.biometric_pin', '999');

        // Filter by date range
        $this->getJson('/api/hr/attendance-logs?start_date=2026-11-12&end_date=2026-11-12')
            ->assertOk()
            ->assertJsonPath('total', 1)
            ->assertJsonPath('logs.0.employee_control_no', '00123');
    }

    public function test_it_validates_invalid_filter_parameters(): void
    {
        Sanctum::actingAs($this->authorizedHrAccount);

        $this->getJson('/api/hr/attendance-logs?processed=invalid&per_page=500&start_date=not-a-date')
            ->assertStatus(422)
            ->assertJsonValidationErrors(['processed', 'per_page', 'start_date']);
    }

    private function createSchemas(): void
    {
        Schema::connection('hr')->create('yOffice', function (Blueprint $table): void {
            $table->string('Codes', 20)->primary();
            $table->string('Descriptions', 255);
            $table->string('Abbr', 255)->nullable();
            $table->string('Active', 10)->default('1');
        });

        Schema::connection('sqlsrv')->create('tblDepartments', function (Blueprint $table): void {
            $table->increments('id');
            $table->string('name', 255);
            $table->unsignedInteger('main_department_id')->nullable();
            $table->boolean('is_inactive')->default(false);
            $table->timestamps();
        });

        Schema::connection('sqlsrv')->create('tblHRAccounts', function (Blueprint $table): void {
            $table->increments('id');
            $table->string('full_name', 150);
            $table->string('position', 150)->nullable();
            $table->string('username', 100)->unique();
            $table->string('password');
            $table->boolean('must_change_password')->default(false);
            $table->unsignedInteger('active_personal_access_token_id')->nullable();
            $table->timestamps();
        });

        Schema::connection('sqlsrv')->create('tblHRModulePermissions', function (Blueprint $table): void {
            $table->increments('id');
            $table->unsignedInteger('hr_account_id');
            $table->string('module_key', 100);
            $table->unsignedInteger('granted_by_hr_account_id')->nullable();
            $table->timestamps();
        });

        Schema::connection('sqlsrv')->create('tblDepartmentAdmins', function (Blueprint $table): void {
            $table->increments('id');
            $table->unsignedInteger('department_id');
            $table->string('full_name', 150);
            $table->string('username', 100)->unique();
            $table->string('password');
            $table->boolean('must_change_password')->default(false);
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
            $table->unsignedBigInteger('department_id')->nullable();
            $table->string('department_name', 150)->nullable();
            $table->string('model_name', 50)->nullable();
            $table->boolean('is_active')->default(true);
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
        DB::connection('hr')->table('yOffice')->insert([
            [
                'Codes' => '001',
                'Descriptions' => 'Office of the City Human Resource Management Officer',
                'Abbr' => 'CHRMO',
                'Active' => '1',
            ],
            [
                'Codes' => '002',
                'Descriptions' => 'City Information and Communications Technology Management Office',
                'Abbr' => 'CICTMO',
                'Active' => '1',
            ],
        ]);

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

        $this->authorizedHrAccount = HRAccount::query()->create([
            'full_name' => 'Authorized HR',
            'position' => 'HR Officer',
            'username' => 'hr_officer',
            'password' => 'secret',
            'must_change_password' => false,
        ]);
        HRModulePermission::query()->create([
            'hr_account_id' => $this->authorizedHrAccount->id,
            'module_key' => 'employee_management',
        ]);

        $this->restrictedHrAccount = HRAccount::query()->create([
            'full_name' => 'Restricted HR',
            'position' => 'HR Viewer',
            'username' => 'hr_viewer',
            'password' => 'secret',
            'must_change_password' => false,
        ]);
        HRModulePermission::query()->create([
            'hr_account_id' => $this->restrictedHrAccount->id,
            'module_key' => 'dashboard',
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
            'department_id' => $this->cictmoDepartment->id,
            'department_name' => $this->cictmoDepartment->name,
            'is_active' => true,
        ]);

        $hrisRows = [
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
        ];
        $version = (string) Cache::get('hris_employees.cache_version', 'v4');
        Cache::put("hris_employees.v5.{$version}.all.all", $hrisRows, now()->addMinutes(5));

        $lookupValues = collect(['00456', '456', '000456', '999', '000999', '00123', '123', '000123'])
            ->map(static fn (string $value): string => trim($value))
            ->unique()
            ->sort()
            ->values()
            ->all();
        Cache::put(
            "hris_employees.v5.{$version}.directory_lookup.".md5(implode('|', $lookupValues)).'.all',
            $hrisRows,
            now()->addMinutes(5)
        );

        AttendanceRawLog::query()->create([
            'device_serial_number' => 'SN-CHRMO-01',
            'biometric_pin' => '123',
            'employee_control_no' => '00123',
            'punch_time' => '2026-11-12 07:58:00',
            'punch_state' => AttendanceRawLog::STATE_CHECK_IN,
            'verify_type' => AttendanceRawLog::VERIFY_FINGERPRINT,
            'sync_source' => 'ADMS',
            'processed_at' => '2026-11-12 07:58:05',
        ]);

        AttendanceRawLog::query()->create([
            'device_serial_number' => 'SN-CHRMO-01',
            'biometric_pin' => '999',
            'employee_control_no' => null,
            'punch_time' => '2026-11-13 08:05:00',
            'punch_state' => AttendanceRawLog::STATE_AUTO,
            'verify_type' => AttendanceRawLog::VERIFY_CARD,
            'sync_source' => 'LAN_POLL',
            'processed_at' => null,
        ]);

        AttendanceRawLog::query()->create([
            'device_serial_number' => 'SN-CICTMO-01',
            'biometric_pin' => '456',
            'employee_control_no' => '00456',
            'punch_time' => '2026-11-13 17:02:00',
            'punch_state' => AttendanceRawLog::STATE_CHECK_OUT,
            'verify_type' => AttendanceRawLog::VERIFY_FACE,
            'sync_source' => 'USB_IMPORT',
            'processed_at' => '2026-11-13 17:02:30',
        ]);
    }

    public function test_it_requires_authorized_hr_account_and_validates_void_and_restore_requests(): void
    {
        $log = AttendanceRawLog::query()->firstOrFail();

        $this->postJson("/api/hr/attendance-logs/{$log->id}/void", [
            'void_reason' => 'Accidental punch',
        ])->assertStatus(401);

        $departmentAdmin = DepartmentAdmin::query()->create([
            'department_id' => $this->chrmoDepartment->id,
            'full_name' => 'Dept Admin',
            'username' => 'dept_admin_void',
            'password' => 'secret',
            'must_change_password' => false,
        ]);

        Sanctum::actingAs($departmentAdmin);
        $this->postJson("/api/hr/attendance-logs/{$log->id}/void", [
            'void_reason' => 'Accidental punch',
        ])->assertStatus(403);

        Sanctum::actingAs($this->restrictedHrAccount);
        $this->postJson("/api/hr/attendance-logs/{$log->id}/void", [
            'void_reason' => 'Accidental punch',
        ])->assertStatus(403);

        Sanctum::actingAs($this->authorizedHrAccount);
        $this->postJson("/api/hr/attendance-logs/{$log->id}/void", [
            'void_reason' => 'no',
        ])->assertStatus(422)
            ->assertJsonValidationErrors(['void_reason']);

        $this->postJson('/api/hr/attendance-logs/999999/void', [
            'void_reason' => 'Accidental punch',
        ])->assertStatus(404);

        $this->postJson("/api/hr/attendance-logs/{$log->id}/restore")
            ->assertStatus(422)
            ->assertJsonPath('message', 'Punch log is not voided.');
    }

    public function test_it_voids_a_punch_recalculates_dtr_filters_by_status_and_restores(): void
    {
        Sanctum::actingAs($this->authorizedHrAccount);

        $mistakePunch = AttendanceRawLog::query()->create([
            'device_serial_number' => 'SN-CHRMO-01',
            'biometric_pin' => '123',
            'employee_control_no' => '00123',
            'punch_time' => '2026-11-16 07:30:00',
            'punch_state' => AttendanceRawLog::STATE_CHECK_IN,
            'verify_type' => AttendanceRawLog::VERIFY_FINGERPRINT,
            'sync_source' => 'ADMS',
        ]);

        $actualArrival = AttendanceRawLog::query()->create([
            'device_serial_number' => 'SN-CHRMO-01',
            'biometric_pin' => '123',
            'employee_control_no' => '00123',
            'punch_time' => '2026-11-16 08:15:00',
            'punch_state' => AttendanceRawLog::STATE_CHECK_IN,
            'verify_type' => AttendanceRawLog::VERIFY_FINGERPRINT,
            'sync_source' => 'ADMS',
        ]);

        AttendanceRawLog::query()->create([
            'device_serial_number' => 'SN-CHRMO-01',
            'biometric_pin' => '123',
            'employee_control_no' => '00123',
            'punch_time' => '2026-11-16 17:05:00',
            'punch_state' => AttendanceRawLog::STATE_CHECK_OUT,
            'verify_type' => AttendanceRawLog::VERIFY_FINGERPRINT,
            'sync_source' => 'ADMS',
        ]);

        /** @var DtrCalculationService $dtrService */
        $dtrService = app(DtrCalculationService::class);
        $beforeDtr = $dtrService->calculateForEmployeeDate('00123', '2026-11-16');
        $this->assertSame('07:30:00', $beforeDtr->am_arrival);
        $this->assertSame(0, (int) $beforeDtr->late_minutes);

        // Void the accidental 07:30 punch
        $this->postJson("/api/hr/attendance-logs/{$mistakePunch->id}/void", [
            'void_reason' => 'Employee double-punched outside shift',
        ])->assertOk()
            ->assertJsonPath('dtr_recalculated', true)
            ->assertJsonPath('dtr_is_adjusted', false)
            ->assertJsonPath('log.is_voided', true)
            ->assertJsonPath('log.void_reason', 'Employee double-punched outside shift')
            ->assertJsonPath('log.voided_by_name', 'Authorized HR');

        // Double-void returns 422
        $this->postJson("/api/hr/attendance-logs/{$mistakePunch->id}/void", [
            'void_reason' => 'Again',
        ])->assertStatus(422);

        // DTR recalculated cleanly without the voided punch
        $afterVoidDtr = DailyTimeRecord::query()
            ->where('employee_control_no', '00123')
            ->where('record_date', '2026-11-16')
            ->firstOrFail();
        $this->assertSame('08:15:00', $afterVoidDtr->am_arrival);
        $this->assertSame(15, (int) $afterVoidDtr->late_minutes);
        $this->assertNotNull($actualArrival->fresh()->processed_at);

        // Default status (all) includes both active and voided logs; active excludes voided; voided shows only voided
        $this->getJson('/api/hr/attendance-logs?start_date=2026-11-16&end_date=2026-11-16')
            ->assertOk()
            ->assertJsonPath('total', 3);

        $this->getJson('/api/hr/attendance-logs?start_date=2026-11-16&end_date=2026-11-16&status=active')
            ->assertOk()
            ->assertJsonPath('total', 2);

        $this->getJson('/api/hr/attendance-logs?status=voided')
            ->assertOk()
            ->assertJsonPath('total', 1)
            ->assertJsonPath('logs.0.id', $mistakePunch->id)
            ->assertJsonPath('logs.0.is_voided', true)
            ->assertJsonPath('logs.0.void_reason', 'Employee double-punched outside shift')
            ->assertJsonPath('logs.0.voided_by_name', 'Authorized HR');

        $this->getJson('/api/hr/attendance-logs?start_date=2026-11-16&end_date=2026-11-16&status=all')
            ->assertOk()
            ->assertJsonPath('total', 3);

        // Restore the punch and verify DTR updates back
        $this->postJson("/api/hr/attendance-logs/{$mistakePunch->id}/restore")
            ->assertOk()
            ->assertJsonPath('dtr_recalculated', true)
            ->assertJsonPath('log.is_voided', false)
            ->assertJsonPath('log.voided_at', null)
            ->assertJsonPath('log.void_reason', null);

        $afterRestoreDtr = $afterVoidDtr->fresh();
        $this->assertSame('07:30:00', $afterRestoreDtr->am_arrival);
        $this->assertSame(0, (int) $afterRestoreDtr->late_minutes);
    }

    public function test_it_preserves_manual_dtr_adjustment_when_voiding_and_blocks_zombie_reimport(): void
    {
        Sanctum::actingAs($this->authorizedHrAccount);

        $punch = AttendanceRawLog::query()->create([
            'device_serial_number' => 'SN-CHRMO-01',
            'biometric_pin' => '123',
            'employee_control_no' => '00123',
            'punch_time' => '2026-11-17 09:10:00',
            'punch_state' => AttendanceRawLog::STATE_CHECK_IN,
            'verify_type' => AttendanceRawLog::VERIFY_FINGERPRINT,
            'sync_source' => 'ADMS',
        ]);

        DailyTimeRecord::query()->create([
            'employee_control_no' => '00123',
            'department_id' => $this->chrmoDepartment->id,
            'record_date' => '2026-11-17',
            'day_of_week' => 'Tuesday',
            'am_arrival' => '08:00:00',
            'am_departure' => '12:00:00',
            'pm_arrival' => '13:00:00',
            'pm_departure' => '17:00:00',
            'late_minutes' => 0,
            'undertime_minutes' => 0,
            'rendered_hours' => 8.00,
            'status' => DailyTimeRecord::STATUS_OFFICIAL_BUSINESS,
            'is_adjusted' => true,
            'adjustment_reason' => DailyTimeRecord::STATUS_OFFICIAL_BUSINESS,
            'adjustment_remarks' => 'Approved OB slip',
            'adjusted_by_admin_id' => 1,
        ]);

        $this->postJson("/api/hr/attendance-logs/{$punch->id}/void", [
            'void_reason' => 'Wrong biometric punch',
        ])->assertOk()
            ->assertJsonPath('dtr_recalculated', true)
            ->assertJsonPath('dtr_is_adjusted', true);

        $preservedDtr = DailyTimeRecord::query()
            ->where('employee_control_no', '00123')
            ->where('record_date', '2026-11-17')
            ->firstOrFail();
        $this->assertTrue((bool) $preservedDtr->is_adjusted);
        $this->assertSame('08:00:00', $preservedDtr->am_arrival);

        // Anti-zombie check: re-syncing the exact same pin + punch_time must treat it as duplicate and keep it voided
        /** @var AttendanceBatchIngestService $ingest */
        $ingest = app(AttendanceBatchIngestService::class);

        $result = $ingest->ingestBatch([[
            'pin' => '123',
            'punch_time' => '2026-11-17 09:10:00',
            'punch_state' => AttendanceRawLog::STATE_CHECK_IN,
            'verify_type' => AttendanceRawLog::VERIFY_FINGERPRINT,
            'raw_payload' => 'reimported',
        ]], 'SN-CHRMO-01', 'ADMS');

        $this->assertSame(0, $result['imported']);
        $this->assertSame(1, $result['duplicates']);
        $this->assertNotNull($punch->fresh()->voided_at);
    }

    public function test_it_handles_broad_search_matching_over_1100_employees(): void
    {
        Sanctum::actingAs($this->authorizedHrAccount);

        $manyRows = [
            [
                'control_no' => '00123',
                'surname' => 'Dela Cruz',
                'firstname' => 'Alpha Juan',
                'middlename' => 'Santos',
                'designation' => 'ADMINISTRATIVE OFFICER IV',
                'office' => $this->chrmoDepartment->name,
                'officeAcronym' => 'CHRMO',
                'status' => 'PERMANENT',
            ],
        ];

        for ($i = 10000; $i < 11200; $i++) {
            $manyRows[] = [
                'control_no' => (string) $i,
                'surname' => 'Sample',
                'firstname' => "Alpha {$i}",
                'middlename' => 'Test',
                'designation' => 'CLERK',
                'office' => $this->chrmoDepartment->name,
                'officeAcronym' => 'CHRMO',
                'status' => 'PERMANENT',
            ];
        }

        $version = (string) Cache::get('hris_employees.cache_version', 'v4');
        Cache::put("hris_employees.v5.{$version}.all.all", $manyRows, now()->addMinutes(5));

        $lookupValues = collect(['00123', '123', '000123'])
            ->map(static fn (string $value): string => trim($value))
            ->unique()
            ->sort()
            ->values()
            ->all();
        Cache::put(
            "hris_employees.v5.{$version}.directory_lookup.".md5(implode('|', $lookupValues)).'.all',
            [$manyRows[0]],
            now()->addMinutes(5)
        );

        $this->getJson('/api/hr/attendance-logs?search=alpha')
            ->assertOk()
            ->assertJsonPath('total', 1)
            ->assertJsonPath('logs.0.employee_control_no', '00123');
    }
}
