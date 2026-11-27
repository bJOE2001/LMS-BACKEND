<?php

namespace Tests\Feature;

use App\Models\Attendance\DailyTimeRecord;
use App\Models\Department;
use App\Models\DepartmentAdmin;
use App\Models\DepartmentHead;
use App\Models\HRAccount;
use App\Models\HrisEmployee;
use App\Models\HrisOffice;
use App\Models\HRModulePermission;
use App\Models\WorkScheduleSetting;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class DtrAccomplishmentTest extends TestCase
{
    private Department $department;

    private DepartmentAdmin $deptAdmin;

    private HRAccount $hrAccount;

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

    public function test_it_requires_department_admin_write_access(): void
    {
        $payload = [
            'employee_control_no' => '022936',
            'field' => 'workload',
            'value' => 'Render time @CICTMO',
            'dates' => ['2026-11-11'],
        ];

        $this->postJson('/api/attendance/dtr/accomplishment', $payload)
            ->assertStatus(401);

        Sanctum::actingAs($this->hrAccount);
        $this->postJson('/api/attendance/dtr/accomplishment', $payload)
            ->assertStatus(403);
    }

    public function test_it_saves_workload_and_quantity_independently_and_preserves_across_reload_and_bulk_print(): void
    {
        Sanctum::actingAs($this->deptAdmin);

        // Save bulk workload for multiple workdays
        $this->postJson('/api/attendance/dtr/accomplishment', [
            'employee_control_no' => '022936',
            'field' => 'workload',
            'value' => 'Render time @CICTMO',
            'dates' => ['2026-11-11', '2026-11-13'],
        ])
            ->assertOk()
            ->assertJsonPath('field', 'workload')
            ->assertJsonPath('value', 'Render time @CICTMO');

        // Save quantity for a single day without overwriting workload
        $this->postJson('/api/attendance/dtr/accomplishment', [
            'employee_control_no' => '022936',
            'field' => 'quantity',
            'value' => '8 hrs',
            'dates' => ['2026-11-11'],
        ])
            ->assertOk()
            ->assertJsonPath('field', 'quantity')
            ->assertJsonPath('value', '8 hrs');

        $day11 = DailyTimeRecord::query()
            ->where('employee_control_no', '022936')
            ->where('record_date', '2026-11-11')
            ->firstOrFail();
        $this->assertSame('Render time @CICTMO', $day11->workload_undertaken);
        $this->assertSame('8 hrs', $day11->workload_quantity);

        $day13 = DailyTimeRecord::query()
            ->where('employee_control_no', '022936')
            ->where('record_date', '2026-11-13')
            ->firstOrFail();
        $this->assertSame('Render time @CICTMO', $day13->workload_undertaken);
        $this->assertNull($day13->workload_quantity);

        // Reload via employeeMonthlyDtr (runs DtrCalculationService) and confirm fields survive
        $dtrResponse = $this->getJson('/api/attendance/dtr/employee/022936?start_date=2026-11-11&end_date=2026-11-13')
            ->assertOk();

        $recordsByDate = collect($dtrResponse->json('records'))->keyBy('record_date');
        $this->assertSame('Render time @CICTMO', $recordsByDate->get('2026-11-11')['workload_undertaken']);
        $this->assertSame('8 hrs', $recordsByDate->get('2026-11-11')['workload_quantity']);
        $this->assertSame('Render time @CICTMO', $recordsByDate->get('2026-11-13')['workload_undertaken']);
        $this->assertNull($recordsByDate->get('2026-11-13')['workload_quantity']);

        // Confirm bulk-print also returns the saved workload and quantity
        $bulkResponse = $this->postJson('/api/attendance/dtr/bulk-print', [
            'control_nos' => ['022936'],
            'start_date' => '2026-11-11',
            'end_date' => '2026-11-13',
        ])->assertOk();

        $bulkRecords = collect($bulkResponse->json('employees_dtr.0.records'))->keyBy('record_date');
        $this->assertSame('Render time @CICTMO', $bulkRecords->get('2026-11-11')['workload_undertaken']);
        $this->assertSame('8 hrs', $bulkRecords->get('2026-11-11')['workload_quantity']);

        // Clear quantity without touching workload
        $this->postJson('/api/attendance/dtr/accomplishment', [
            'employee_control_no' => '022936',
            'field' => 'quantity',
            'value' => null,
            'dates' => ['2026-11-11'],
        ])->assertOk();

        $day11Fresh = $day11->fresh();
        $this->assertSame('Render time @CICTMO', $day11Fresh->workload_undertaken);
        $this->assertNull($day11Fresh->workload_quantity);
    }

    public function test_it_validates_payload_fields_and_quantity_length(): void
    {
        Sanctum::actingAs($this->deptAdmin);

        $this->postJson('/api/attendance/dtr/accomplishment', [
            'employee_control_no' => '022936',
            'field' => 'invalid_field',
            'value' => 'Test',
            'dates' => ['2026-11-11'],
        ])->assertStatus(422);

        $this->postJson('/api/attendance/dtr/accomplishment', [
            'employee_control_no' => '022936',
            'field' => 'quantity',
            'value' => str_repeat('x', 101),
            'dates' => ['2026-11-11'],
        ])->assertStatus(422);
    }

    public function test_it_returns_department_head_actual_designation_for_in_charge_signatory(): void
    {
        DepartmentHead::query()->create([
            'department_id' => $this->department->id,
            'control_no' => '003041',
            'firstname' => 'Joseph Nelson',
            'middlename' => 'N.',
            'surname' => 'Briones',
            'full_name' => 'JOSEPH NELSON N. BRIONES',
            'designation' => 'CITY GOVERNMENT DEPARTMENT HEAD I',
            'office' => $this->department->name,
        ]);

        Sanctum::actingAs($this->deptAdmin);

        $this->getJson('/api/attendance/dtr/employee/022936?start_date=2026-11-11&end_date=2026-11-13')
            ->assertOk()
            ->assertJsonPath('in_charge.name', 'JOSEPH NELSON N. BRIONES')
            ->assertJsonPath('in_charge.title', 'CITY GOVERNMENT DEPARTMENT HEAD I')
            ->assertJsonPath('employee.in_charge.title', 'CITY GOVERNMENT DEPARTMENT HEAD I');

        $this->postJson('/api/attendance/dtr/bulk-print', [
            'control_nos' => ['022936'],
            'start_date' => '2026-11-11',
            'end_date' => '2026-11-13',
        ])
            ->assertOk()
            ->assertJsonPath('employees_dtr.0.in_charge.title', 'CITY GOVERNMENT DEPARTMENT HEAD I');
    }

    public function test_honorarium_employee_includes_accomplishment_report_flag(): void
    {
        $version = (string) Cache::get('hris_employees.cache_version', 'v4');
        $honorariumRow = [
            'control_no' => '099999',
            'surname' => 'SANTOS',
            'firstname' => 'MARIA',
            'middlename' => 'L.',
            'designation' => 'LECTURER',
            'office' => $this->department->name,
            'officeAcronym' => 'CICTMO',
            'status' => 'HONORARIUM',
        ];

        $lookupValues = collect(['099999', '99999'])
            ->map(static fn (string $value): string => trim($value))
            ->unique()
            ->sort()
            ->values()
            ->all();
        Cache::put(
            "hris_employees.v5.{$version}.directory_lookup.".md5(implode('|', $lookupValues)).'.active',
            [$honorariumRow],
            now()->addMinutes(5)
        );
        Cache::put(
            "hris_employees.v5.{$version}.directory_lookup.".md5(implode('|', $lookupValues)).'.all',
            [$honorariumRow],
            now()->addMinutes(5)
        );

        Sanctum::actingAs($this->deptAdmin);

        $this->getJson('/api/attendance/dtr/employee/099999?start_date=2026-11-11&end_date=2026-11-13')
            ->assertOk()
            ->assertJsonPath('employee.employment_status', 'HONORARIUM')
            ->assertJsonPath('employee.is_contractual', true);

        $this->postJson('/api/attendance/dtr/bulk-print', [
            'control_nos' => ['099999'],
            'start_date' => '2026-11-11',
            'end_date' => '2026-11-13',
        ])
            ->assertOk()
            ->assertJsonPath('employees_dtr.0.employee.is_contractual', true);
    }

    public function test_manual_slot_remark_override_without_time_marks_present_and_survives_recalculation(): void
    {
        Sanctum::actingAs($this->deptAdmin);

        $this->postJson('/api/attendance/dtr/override', [
            'employee_control_no' => '022936',
            'date' => '2026-11-17',
            'am_arrival' => null,
            'am_departure' => null,
            'pm_arrival' => null,
            'pm_departure' => null,
            'remark' => 'W.S',
            'adjusted_slots' => ['am_arrival' => 'W.S'],
        ])
            ->assertOk()
            ->assertJsonPath('dtr.status', DailyTimeRecord::STATUS_PRESENT)
            ->assertJsonPath('dtr.adjusted_slots.am_arrival', 'W.S');

        $dtrResponse = $this->getJson('/api/attendance/dtr/employee/022936?start_date=2026-11-16&end_date=2026-11-18')
            ->assertOk();

        $day17 = collect($dtrResponse->json('records'))->firstWhere('record_date', '2026-11-17');
        $this->assertNotNull($day17);
        $this->assertSame(DailyTimeRecord::STATUS_PRESENT, $day17['status']);
        $this->assertSame('W.S', $day17['adjusted_slots']['am_arrival'] ?? null);
    }

    private function createSchemas(): void
    {
        Schema::connection('hr')->create('yOffice', function (Blueprint $table): void {
            $table->string('Codes', 20)->primary();
            $table->string('Descriptions', 255);
            $table->string('Abbr', 255)->nullable();
            $table->string('Active', 10)->default('1');
        });

        Schema::connection('hr')->create('xService', function (Blueprint $table): void {
            $table->string('ControlNo', 64);
            $table->string('Charges', 200)->nullable();
            $table->date('FromDate')->nullable();
            $table->date('ToDate')->nullable();
            $table->integer('Grades')->nullable();
        });

        Schema::connection('sqlsrv')->create('tblDepartments', function (Blueprint $table): void {
            $table->increments('id');
            $table->string('name', 255);
            $table->unsignedInteger('main_department_id')->nullable();
            $table->boolean('is_inactive')->default(false);
            $table->timestamps();
        });

        Schema::connection('sqlsrv')->create('tblDepartmentHeads', function (Blueprint $table): void {
            $table->increments('id');
            $table->unsignedInteger('department_id');
            $table->string('control_no', 64)->nullable();
            $table->string('firstname', 100)->nullable();
            $table->string('middlename', 100)->nullable();
            $table->string('surname', 100)->nullable();
            $table->string('full_name', 200)->nullable();
            $table->string('designation', 150)->nullable();
            $table->string('office', 150)->nullable();
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
            $table->string('workload_undertaken', 500)->nullable();
            $table->string('workload_quantity', 100)->nullable();
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
            'Codes' => '002',
            'Descriptions' => 'City Information and Communications Technology Management Office',
            'Abbr' => 'CICTMO',
            'Active' => '1',
        ]);

        $this->department = Department::query()->create([
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

        $this->deptAdmin = DepartmentAdmin::query()->create([
            'department_id' => $this->department->id,
            'full_name' => 'CICTMO Admin',
            'username' => 'cictmo_admin',
            'password' => 'secret',
            'must_change_password' => false,
        ]);

        $this->hrAccount = HRAccount::query()->create([
            'full_name' => 'HR Viewer',
            'position' => 'HR Officer',
            'username' => 'hr_viewer',
            'password' => 'secret',
            'must_change_password' => false,
        ]);

        HRModulePermission::query()->create([
            'hr_account_id' => $this->hrAccount->id,
            'module_key' => 'employee_management',
        ]);

        $hrisRows = [
            [
                'control_no' => '022936',
                'surname' => 'BASADRE',
                'firstname' => 'BELLY JOE',
                'middlename' => 'B.',
                'designation' => 'COMPUTER OPERATOR',
                'office' => $this->department->name,
                'officeAcronym' => 'CICTMO',
                'status' => 'CONTRACTUAL',
            ],
        ];

        $version = (string) Cache::get('hris_employees.cache_version', 'v4');
        Cache::put("hris_employees.v5.{$version}.all.all", $hrisRows, now()->addMinutes(5));

        $lookupValues = collect(['022936', '22936'])
            ->map(static fn (string $value): string => trim($value))
            ->unique()
            ->sort()
            ->values()
            ->all();
        Cache::put(
            "hris_employees.v5.{$version}.directory_lookup.".md5(implode('|', $lookupValues)).'.active',
            $hrisRows,
            now()->addMinutes(5)
        );
        Cache::put(
            "hris_employees.v5.{$version}.directory_lookup.".md5(implode('|', $lookupValues)).'.all',
            $hrisRows,
            now()->addMinutes(5)
        );
    }
}
