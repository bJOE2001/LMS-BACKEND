<?php

namespace Tests\Feature;

use App\Models\Attendance\BiometricDevice;
use App\Models\Attendance\BiometricEnrollment;
use App\Models\Attendance\BiometricTransferLog;
use App\Models\Department;
use App\Models\DepartmentAdmin;
use App\Models\EmployeeDepartmentAssignment;
use App\Models\HrisEmployee;
use App\Models\HrisOffice;
use App\Services\Attendance\BiometricTransferService;
use App\Services\Attendance\LanBioTemplateSyncService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class BiometricTransferLogCleanupTest extends TestCase
{
    private Department $chrmoDepartment;

    private Department $cictmoDepartment;

    private Department $divisionWithoutTerminal;

    private DepartmentAdmin $cictmoAdmin;

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

    public function test_remove_on_department_deassignment_skips_bogus_log_when_office_has_no_device(): void
    {
        $enrollment = BiometricEnrollment::query()->create([
            'employee_control_no' => '00123',
            'employee_name' => 'Juan S. Dela Cruz',
            'status' => BiometricEnrollment::STATUS_REGISTERED,
            'enrollment_device_sn' => 'SN-CHRMO-01',
            'synced_devices' => ['SN-LEGACY-01'],
        ]);

        /** @var BiometricTransferService $service */
        $service = app(BiometricTransferService::class);
        $result = $service->removeOnDepartmentDeassignment('00123', $this->divisionWithoutTerminal->id, $this->cictmoAdmin->id);

        $this->assertFalse($result['removed']);
        $this->assertSame(0, BiometricTransferLog::query()->count());
        $this->assertSame([], $enrollment->fresh()->synced_devices);
    }

    public function test_remove_logs_when_office_has_active_device_and_transfer_preserves_prior_source(): void
    {
        BiometricEnrollment::query()->create([
            'employee_control_no' => '00123',
            'employee_name' => 'Juan S. Dela Cruz',
            'status' => BiometricEnrollment::STATUS_REGISTERED,
            'enrollment_device_sn' => 'SN-CHRMO-01',
            'synced_devices' => ['SN-CHRMO-01'],
        ]);

        /** @var BiometricTransferService $service */
        $service = app(BiometricTransferService::class);

        // Removing from CHRMO (has active SN-CHRMO-01) records a valid REMOVE log with source_device_sn
        $removeResult = $service->removeOnDepartmentDeassignment('00123', $this->chrmoDepartment->id, $this->cictmoAdmin->id);
        $this->assertTrue($removeResult['removed']);

        $removeLog = BiometricTransferLog::query()->firstOrFail();
        $this->assertSame(BiometricTransferLog::ACTION_REMOVE, $removeLog->action_type);
        $this->assertSame($this->chrmoDepartment->id, (int) $removeLog->from_department_id);
        $this->assertSame('SN-CHRMO-01', $removeLog->from_device_sn);

        // Pulling into CICTMO without passing $sourceDepartmentId still preserves CHRMO / SN-CHRMO-01 as source
        $transferResult = $service->transferOnDepartmentAssignment('00123', $this->cictmoDepartment->id, $this->cictmoAdmin->id);
        $this->assertTrue($transferResult['transferred']);

        $transferLog = BiometricTransferLog::query()->orderByDesc('id')->firstOrFail();
        $this->assertSame(BiometricTransferLog::ACTION_TRANSFER, $transferLog->action_type);
        $this->assertSame($this->chrmoDepartment->id, (int) $transferLog->from_department_id);
        $this->assertSame('SN-CHRMO-01', $transferLog->from_device_sn);
        $this->assertSame($this->cictmoDepartment->id, (int) $transferLog->to_department_id);
        $this->assertSame('SN-CICTMO-01', $transferLog->to_device_sn);
    }

    public function test_admin_pull_to_office_logs_transfer_action_type(): void
    {
        Sanctum::actingAs($this->cictmoAdmin);

        EmployeeDepartmentAssignment::query()->create([
            'employee_control_no' => '00123',
            'department_id' => $this->cictmoDepartment->id,
        ]);

        BiometricEnrollment::query()->create([
            'employee_control_no' => '00123',
            'employee_name' => 'Juan S. Dela Cruz',
            'status' => BiometricEnrollment::STATUS_REGISTERED,
            'enrollment_device_sn' => 'SN-CHRMO-01',
            'synced_devices' => ['SN-CHRMO-01'],
        ]);

        $this->mock(LanBioTemplateSyncService::class);

        $this->postJson('/api/attendance/biometric/pull-to-office', [
            'employee_control_no' => '00123',
            'device_serial_number' => 'SN-CICTMO-01',
        ])->assertOk();

        $log = BiometricTransferLog::query()->orderByDesc('id')->firstOrFail();
        $this->assertSame(BiometricTransferLog::ACTION_TRANSFER, $log->action_type);
        $this->assertSame($this->chrmoDepartment->id, (int) $log->from_department_id);
        $this->assertSame('SN-CHRMO-01', $log->from_device_sn);
        $this->assertSame($this->cictmoDepartment->id, (int) $log->to_department_id);
        $this->assertSame('SN-CICTMO-01', $log->to_device_sn);

        $updatedEnrollment = BiometricEnrollment::query()->where('employee_control_no', '00123')->firstOrFail();
        $this->assertSame($this->cictmoAdmin->id, $updatedEnrollment->enrolled_by_user_id);
    }

    public function test_department_admin_can_pull_employee_directly_from_another_active_office_and_logs_single_transfer(): void
    {
        EmployeeDepartmentAssignment::query()->create([
            'employee_control_no' => '00123',
            'department_id' => $this->chrmoDepartment->id,
            'surname' => 'Dela Cruz',
            'firstname' => 'Juan',
            'middlename' => 'Santos',
            'department' => $this->chrmoDepartment->name,
        ]);

        BiometricEnrollment::query()->create([
            'employee_control_no' => '00123',
            'employee_name' => 'Juan S. Dela Cruz',
            'status' => BiometricEnrollment::STATUS_REGISTERED,
            'enrollment_device_sn' => 'SN-CHRMO-01',
            'synced_devices' => ['SN-CHRMO-01'],
        ]);

        $this->seedEmployeeCache([
            'assigned_department_id' => $this->chrmoDepartment->id,
            'assigned_department_name' => $this->chrmoDepartment->name,
            'assignedDepartmentAcronym' => 'CHRMO',
        ]);

        $this->mock(LanBioTemplateSyncService::class);
        Sanctum::actingAs($this->cictmoAdmin);

        $optionsResponse = $this->getJson('/api/admin/employee-options')
            ->assertOk();

        $matchingOption = collect($optionsResponse->json('employees'))
            ->firstWhere('control_no', '00123');
        $this->assertNotNull($matchingOption);
        $this->assertSame($this->chrmoDepartment->id, (int) $matchingOption['assigned_department_id']);

        $this->postJson('/api/admin/employees', [
            'control_no' => '00123',
        ])->assertOk();

        $assignment = EmployeeDepartmentAssignment::query()
            ->with('assignedDepartment')
            ->where('employee_control_no', '00123')
            ->firstOrFail();
        $this->assertSame($this->cictmoDepartment->id, (int) $assignment->department_id);
        $this->assertSame($this->cictmoDepartment->name, $assignment->department);
        $this->assertSame($this->cictmoDepartment->name, $assignment->assignedDepartment?->name);
        $this->assertSame($this->cictmoAdmin->id, (int) $assignment->assigned_by_department_admin_id);

        $this->assertSame(1, BiometricTransferLog::query()->count());
        $log = BiometricTransferLog::query()->firstOrFail();
        $this->assertSame(BiometricTransferLog::ACTION_TRANSFER, $log->action_type);
        $this->assertSame($this->chrmoDepartment->id, (int) $log->from_department_id);
        $this->assertSame('SN-CHRMO-01', $log->from_device_sn);
        $this->assertSame($this->cictmoDepartment->id, (int) $log->to_department_id);
        $this->assertSame('SN-CICTMO-01', $log->to_device_sn);

        $enrollment = BiometricEnrollment::query()->where('employee_control_no', '00123')->firstOrFail();
        $this->assertSame($this->cictmoAdmin->id, $enrollment->enrolled_by_user_id);

        $this->seedEmployeeCache([
            'assigned_department_id' => $this->cictmoDepartment->id,
            'assigned_department_name' => $this->cictmoDepartment->name,
            'assignedDepartmentAcronym' => 'CICTMO',
            'is_department_reassigned' => true,
        ]);

        $this->postJson('/api/admin/employees', [
            'control_no' => '00123',
        ])->assertOk()
            ->assertJsonPath('message', 'Employee is already pulled into your office.');

        $optionsAfterResponse = $this->getJson('/api/admin/employee-options')
            ->assertOk();
        $this->assertNull(collect($optionsAfterResponse->json('employees'))->firstWhere('control_no', '00123'));
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
            $table->string('surname', 120)->nullable();
            $table->string('firstname', 120)->nullable();
            $table->string('middlename', 120)->nullable();
            $table->string('department', 100)->nullable();
            $table->unsignedInteger('assigned_by_department_admin_id')->nullable();
            $table->timestamp('assigned_at')->nullable();
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
            $table->boolean('is_primary')->default(true);
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

        Schema::connection('bio')->create('tblBiometricTemplates', function (Blueprint $table): void {
            $table->increments('id');
            $table->string('employee_control_no', 64);
            $table->string('communication_mode', 20)->default('ADMS');
            $table->string('template_type', 20)->default('FINGER');
            $table->integer('finger_id')->default(0);
            $table->text('template_data')->nullable();
            $table->timestamps();
        });

        Schema::connection('bio')->create('tblBiometricDeviceCommands', function (Blueprint $table): void {
            $table->increments('id');
            $table->string('device_serial_number', 50);
            $table->string('command_type', 50);
            $table->text('command_payload')->nullable();
            $table->string('employee_control_no', 64)->nullable();
            $table->string('employee_name', 150)->nullable();
            $table->string('status', 30)->default('PENDING');
            $table->integer('return_code')->nullable();
            $table->text('response_payload')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->timestamp('executed_at')->nullable();
            $table->unsignedBigInteger('created_by_user_id')->nullable();
            $table->timestamps();
        });

        Schema::connection('bio')->create('tblAttendanceLogs', function (Blueprint $table): void {
            $table->increments('id');
            $table->string('device_serial_number', 50)->nullable();
            $table->string('biometric_pin', 64);
            $table->string('employee_control_no', 64)->nullable();
            $table->dateTime('punch_time');
            $table->timestamps();
        });

        Schema::connection('bio')->create('tblBiometricTransferLogs', function (Blueprint $table): void {
            $table->increments('id');
            $table->string('employee_control_no', 50);
            $table->string('employee_name', 150)->nullable();
            $table->string('action_type', 30);
            $table->unsignedInteger('from_department_id')->nullable();
            $table->string('from_office_name', 150)->nullable();
            $table->string('from_device_sn', 100)->nullable();
            $table->unsignedInteger('to_department_id')->nullable();
            $table->string('to_office_name', 150)->nullable();
            $table->string('to_device_sn', 100)->nullable();
            $table->string('status', 30);
            $table->text('remarks')->nullable();
            $table->unsignedInteger('performed_by_user_id')->nullable();
            $table->string('performed_by_name', 150)->nullable();
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
        $this->divisionWithoutTerminal = Department::query()->create([
            'name' => 'CICTMO - PROGRAMMING',
            'main_department_id' => $this->cictmoDepartment->id,
            'is_inactive' => false,
        ]);

        $this->cictmoAdmin = DepartmentAdmin::query()->create([
            'department_id' => $this->cictmoDepartment->id,
            'full_name' => 'CICTMO Admin',
            'username' => 'cictmo_admin',
            'password' => 'secret',
            'must_change_password' => false,
        ]);

        BiometricDevice::query()->create([
            'device_name' => 'CHRMO Enrollment',
            'serial_number' => 'SN-CHRMO-01',
            'communication_mode' => 'ADMS',
            'department_id' => $this->chrmoDepartment->id,
            'department_name' => $this->chrmoDepartment->name,
            'is_active' => true,
        ]);
        BiometricDevice::query()->create([
            'device_name' => 'CICTMO Terminal',
            'serial_number' => 'SN-CICTMO-01',
            'communication_mode' => 'ADMS',
            'department_id' => $this->cictmoDepartment->id,
            'department_name' => $this->cictmoDepartment->name,
            'is_active' => true,
        ]);

        $this->seedEmployeeCache();
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function seedEmployeeCache(array $overrides = []): void
    {
        HrisEmployee::flushCache();

        $version = (string) Cache::get('hris_employees.cache_version', 'v4');
        $lookupHashes = [
            md5('00123'),
            md5('000123|00123|123'),
        ];

        $employeeRow = array_merge([
            'control_no' => '00123',
            'surname' => 'Dela Cruz',
            'firstname' => 'Juan',
            'middlename' => 'Santos',
            'designation' => 'CLERK',
            'office' => $this->chrmoDepartment->name,
            'officeAcronym' => 'CHRMO',
            'hris_office' => $this->chrmoDepartment->name,
            'hrisOfficeAcronym' => 'CHRMO',
            'assigned_department_id' => null,
            'assigned_department_name' => null,
            'assignedDepartmentAcronym' => null,
            'is_department_reassigned' => false,
            'rate_mon' => 20000,
            'status' => 'PERMANENT',
        ], $overrides);

        foreach (['all', 'active'] as $activityKey) {
            Cache::put("hris_employees.v5.{$version}.all.{$activityKey}", [$employeeRow], now()->addMinutes(5));
            foreach ($lookupHashes as $lookupHash) {
                Cache::put("hris_employees.v5.{$version}.directory_lookup.{$lookupHash}.{$activityKey}", [$employeeRow], now()->addMinutes(5));
            }
        }
    }
}
