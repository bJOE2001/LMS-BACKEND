<?php

namespace Tests\Feature;

use App\Models\Attendance\AttendanceRawLog;
use App\Models\Attendance\BiometricDevice;
use App\Models\Department;
use App\Models\DepartmentAdmin;
use App\Models\HRAccount;
use App\Models\HrisEmployee;
use App\Models\HrisOffice;
use App\Models\HRModulePermission;
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
            $table->string('work_code', 20)->nullable();
            $table->string('sync_source', 20)->default('ADMS');
            $table->string('raw_payload', 255)->nullable();
            $table->timestamp('processed_at')->nullable();
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
}
