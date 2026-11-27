<?php

namespace Tests\Feature;

use App\Models\Attendance\AttendanceRawLog;
use App\Models\Attendance\DailyTimeRecord;
use App\Models\Attendance\DtrDeleteRequest;
use App\Models\Department;
use App\Models\DepartmentAdmin;
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

class DtrDeleteRequestTest extends TestCase
{
    private Department $department;

    private Department $otherDepartment;

    private DepartmentAdmin $deptAdmin;

    private DepartmentAdmin $otherDeptAdmin;

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

    public function test_override_time_blocks_overwriting_or_clearing_original_biometric_punch_while_allowing_empty_slot_override(): void
    {
        AttendanceRawLog::query()->create([
            'device_serial_number' => 'DEV-01',
            'biometric_pin' => '022936',
            'employee_control_no' => '022936',
            'punch_time' => '2026-11-11 08:02:00',
            'punch_state' => 0,
            'verify_type' => 15,
            'sync_source' => 'ADMS',
        ]);

        AttendanceRawLog::query()->create([
            'device_serial_number' => 'DEV-01',
            'biometric_pin' => '022936',
            'employee_control_no' => '022936',
            'punch_time' => '2026-11-11 12:01:00',
            'punch_state' => 1,
            'verify_type' => 15,
            'sync_source' => 'ADMS',
        ]);

        DailyTimeRecord::query()->create([
            'employee_control_no' => '022936',
            'department_id' => $this->department->id,
            'record_date' => '2026-11-11',
            'day_of_week' => 'Wednesday',
            'am_arrival' => '08:02:00',
            'am_departure' => '12:01:00',
            'pm_arrival' => null,
            'pm_departure' => null,
            'rendered_hours' => 4.00,
            'status' => DailyTimeRecord::STATUS_PRESENT,
            'is_adjusted' => false,
            'adjusted_slots' => null,
        ]);

        Sanctum::actingAs($this->deptAdmin);

        // Clearing AM In biometric punch directly is blocked
        $this->postJson('/api/attendance/dtr/override', [
            'employee_control_no' => '022936',
            'date' => '2026-11-11',
            'am_arrival' => null,
            'am_departure' => '12:01 PM',
            'pm_arrival' => null,
            'pm_departure' => null,
            'adjusted_slots' => ['am_arrival' => 'O.F.'],
        ])->assertStatus(422);

        // Overwriting AM In biometric time with another time directly is blocked
        $this->postJson('/api/attendance/dtr/override', [
            'employee_control_no' => '022936',
            'date' => '2026-11-11',
            'am_arrival' => '8:00 AM',
            'am_departure' => '12:01 PM',
            'pm_arrival' => null,
            'pm_departure' => null,
            'adjusted_slots' => ['am_arrival' => 'TIME'],
        ])->assertStatus(422);

        // Overriding empty PM slots while keeping AM biometric punches untouched succeeds
        $this->postJson('/api/attendance/dtr/override', [
            'employee_control_no' => '022936',
            'date' => '2026-11-11',
            'am_arrival' => '8:02 AM',
            'am_departure' => '12:01 PM',
            'pm_arrival' => null,
            'pm_departure' => null,
            'remark' => 'O.F.',
            'adjusted_slots' => ['pm_arrival' => 'O.F.', 'pm_departure' => 'O.F.'],
        ])->assertOk();
    }

    public function test_dept_admin_can_submit_and_list_delete_requests_with_validation_and_duplicate_checks(): void
    {
        DailyTimeRecord::query()->create([
            'employee_control_no' => '022936',
            'department_id' => $this->department->id,
            'record_date' => '2026-11-12',
            'day_of_week' => 'Thursday',
            'am_arrival' => '08:05:00',
            'am_departure' => '12:00:00',
            'pm_arrival' => null,
            'pm_departure' => null,
            'rendered_hours' => 8.00,
            'status' => DailyTimeRecord::STATUS_PRESENT,
            'is_adjusted' => true,
            'adjustment_reason' => 'MANUAL_OVERRIDE',
            'adjusted_slots' => ['pm_arrival' => 'O.F.', 'pm_departure' => 'O.F.'],
        ]);

        // HR cannot submit delete requests
        Sanctum::actingAs($this->hrAccount);
        $this->postJson('/api/attendance/dtr/delete-requests', [
            'employee_control_no' => '022936',
            'date' => '2026-11-12',
            'period' => 'single',
            'slots' => ['am_arrival'],
            'reason' => 'Wrong tap',
        ])->assertStatus(403);

        Sanctum::actingAs($this->deptAdmin);

        // Empty slot request is rejected when no biometric/override exists (on a clean day)
        $this->postJson('/api/attendance/dtr/delete-requests', [
            'employee_control_no' => '022936',
            'date' => '2026-11-13',
            'period' => 'single',
            'slots' => ['am_arrival'],
            'reason' => 'No data test',
        ])->assertStatus(422);

        // Submit delete request for biometric punch
        $resBio = $this->postJson('/api/attendance/dtr/delete-requests', [
            'employee_control_no' => '022936',
            'date' => '2026-11-12',
            'period' => 'single',
            'slots' => ['am_arrival'],
            'reason' => 'Erroneous biometric punch',
        ])->assertStatus(201);

        $this->assertSame('biometric', $resBio->json('delete_request.previous_snapshot.am_arrival.source'));
        $this->assertSame('08:05:00', $resBio->json('delete_request.previous_snapshot.am_arrival.time'));

        // Overlapping pending request on am_arrival is blocked
        $this->postJson('/api/attendance/dtr/delete-requests', [
            'employee_control_no' => '022936',
            'date' => '2026-11-12',
            'period' => 'am',
            'slots' => ['am_arrival', 'am_departure'],
            'reason' => 'Duplicate overlap test',
        ])->assertStatus(422);

        // Submitting delete request for manual override/remark on PM slots succeeds
        $resOverride = $this->postJson('/api/attendance/dtr/delete-requests', [
            'employee_control_no' => '022936',
            'date' => '2026-11-12',
            'period' => 'pm',
            'slots' => ['pm_arrival', 'pm_departure'],
            'reason' => 'Mistaken O.F. entry',
        ])->assertStatus(201);

        $this->assertSame('override', $resOverride->json('delete_request.previous_snapshot.pm_arrival.source'));
        $this->assertSame('O.F.', $resOverride->json('delete_request.previous_snapshot.pm_arrival.remark'));

        // employeeMonthlyDtr includes both pending delete requests
        $this->getJson('/api/attendance/dtr/employee/022936?start_date=2026-11-01&end_date=2026-11-30')
            ->assertOk()
            ->assertJsonCount(2, 'pending_delete_requests');
    }

    public function test_dept_admin_can_cancel_own_pending_request_but_not_other_office_or_non_pending(): void
    {
        $req = DtrDeleteRequest::query()->create([
            'employee_control_no' => '022936',
            'employee_name' => 'BELLY JOE BASADRE',
            'department_id' => $this->department->id,
            'office_name' => $this->department->name,
            'record_date' => '2026-11-12',
            'period' => 'single',
            'slots' => ['am_arrival'],
            'previous_snapshot' => ['am_arrival' => ['time' => '08:05:00', 'remark' => null, 'source' => 'biometric']],
            'reason' => 'Wrong tap',
            'status' => DtrDeleteRequest::STATUS_PENDING,
            'requested_by_admin_id' => $this->deptAdmin->id,
            'requested_by_name' => 'CICTMO Admin',
        ]);

        // Another office admin cannot cancel
        Sanctum::actingAs($this->otherDeptAdmin);
        $this->postJson("/api/attendance/dtr/delete-requests/{$req->id}/cancel")
            ->assertStatus(403);

        // Owning office admin can cancel pending request
        Sanctum::actingAs($this->deptAdmin);
        $this->postJson("/api/attendance/dtr/delete-requests/{$req->id}/cancel")
            ->assertOk()
            ->assertJsonPath('delete_request.status', 'cancelled');

        // Cannot cancel again once cancelled
        $this->postJson("/api/attendance/dtr/delete-requests/{$req->id}/cancel")
            ->assertStatus(422);
    }

    public function test_hr_approve_clears_biometric_punch_recalculates_and_prevents_resync_restoration(): void
    {
        AttendanceRawLog::query()->create([
            'device_serial_number' => 'DEV-01',
            'biometric_pin' => '022936',
            'employee_control_no' => '022936',
            'punch_time' => '2026-11-11 08:02:00',
            'punch_state' => 0,
            'verify_type' => 15,
            'sync_source' => 'ADMS',
        ]);

        $dtr = DailyTimeRecord::query()->create([
            'employee_control_no' => '022936',
            'department_id' => $this->department->id,
            'record_date' => '2026-11-11',
            'day_of_week' => 'Wednesday',
            'am_arrival' => '08:02:00',
            'am_departure' => null,
            'pm_arrival' => null,
            'pm_departure' => null,
            'rendered_hours' => 0.00,
            'status' => DailyTimeRecord::STATUS_PRESENT,
            'is_adjusted' => false,
            'adjusted_slots' => null,
        ]);

        Sanctum::actingAs($this->deptAdmin);
        $createRes = $this->postJson('/api/attendance/dtr/delete-requests', [
            'employee_control_no' => '022936',
            'date' => '2026-11-11',
            'period' => 'single',
            'slots' => ['am_arrival'],
            'reason' => 'Accidental tap',
        ])->assertStatus(201);

        $requestId = $createRes->json('delete_request.id');

        // Dept Admin cannot approve
        $this->postJson("/api/attendance/dtr/delete-requests/{$requestId}/approve")
            ->assertStatus(403);

        // HR approves delete request
        Sanctum::actingAs($this->hrAccount);
        $this->postJson("/api/attendance/dtr/delete-requests/{$requestId}/approve", [
            'hr_remarks' => 'Verified with office head',
        ])
            ->assertOk()
            ->assertJsonPath('delete_request.status', 'approved')
            ->assertJsonPath('dtr.am_arrival', null)
            ->assertJsonPath('dtr.status', DailyTimeRecord::STATUS_ABSENT)
            ->assertJsonPath('dtr.is_adjusted', true);

        // Reloading monthly DTR keeps am_arrival null (raw biometric log does not resurrect it)
        $reloadRes = $this->getJson('/api/attendance/dtr/employee/022936?start_date=2026-11-11&end_date=2026-11-11')
            ->assertOk()
            ->assertJsonCount(0, 'pending_delete_requests');

        $record = collect($reloadRes->json('records'))->firstWhere('record_date', '2026-11-11');
        $this->assertNotNull($record);
        $this->assertNull($record['am_arrival']);
        $this->assertSame(DailyTimeRecord::STATUS_ABSENT, $record['status']);
    }

    public function test_hr_reject_leaves_dtr_untouched_and_requires_remarks(): void
    {
        AttendanceRawLog::query()->create([
            'device_serial_number' => 'DEV-01',
            'biometric_pin' => '022936',
            'employee_control_no' => '022936',
            'punch_time' => '2026-11-16 08:01:00',
            'punch_state' => 0,
            'verify_type' => 15,
            'sync_source' => 'ADMS',
        ]);

        AttendanceRawLog::query()->create([
            'device_serial_number' => 'DEV-01',
            'biometric_pin' => '022936',
            'employee_control_no' => '022936',
            'punch_time' => '2026-11-16 12:00:00',
            'punch_state' => 1,
            'verify_type' => 15,
            'sync_source' => 'ADMS',
        ]);

        $dtr = DailyTimeRecord::query()->create([
            'employee_control_no' => '022936',
            'department_id' => $this->department->id,
            'record_date' => '2026-11-16',
            'day_of_week' => 'Monday',
            'am_arrival' => '08:01:00',
            'am_departure' => '12:00:00',
            'rendered_hours' => 4.00,
            'status' => DailyTimeRecord::STATUS_PRESENT,
            'is_adjusted' => false,
        ]);

        Sanctum::actingAs($this->deptAdmin);
        $createRes = $this->postJson('/api/attendance/dtr/delete-requests', [
            'employee_control_no' => '022936',
            'date' => '2026-11-16',
            'period' => 'single',
            'slots' => ['am_arrival'],
            'reason' => 'Duplicate tap',
        ])->assertStatus(201);

        $requestId = $createRes->json('delete_request.id');

        Sanctum::actingAs($this->hrAccount);

        // Missing hr_remarks fails validation
        $this->postJson("/api/attendance/dtr/delete-requests/{$requestId}/reject", [
            'hr_remarks' => '',
        ])->assertStatus(422);

        $this->postJson("/api/attendance/dtr/delete-requests/{$requestId}/reject", [
            'hr_remarks' => 'Valid attendance punch confirmed',
        ])
            ->assertOk()
            ->assertJsonPath('delete_request.status', 'rejected')
            ->assertJsonPath('delete_request.hr_remarks', 'Valid attendance punch confirmed');

        $dtr->refresh();
        $this->assertSame('08:01:00', $dtr->am_arrival);
        $this->assertSame('12:00:00', $dtr->am_departure);
        $this->assertFalse($dtr->is_adjusted);
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

        Schema::connection('bio')->create('tblDtrDeleteRequests', function (Blueprint $table): void {
            $table->increments('id');
            $table->unsignedBigInteger('daily_time_record_id')->nullable();
            $table->string('employee_control_no', 50);
            $table->string('employee_name', 255)->nullable();
            $table->unsignedBigInteger('department_id')->nullable();
            $table->string('office_name', 255)->nullable();
            $table->date('record_date');
            $table->string('period', 20);
            $table->text('slots');
            $table->text('previous_snapshot');
            $table->string('reason', 500);
            $table->string('status', 20)->default('pending');
            $table->unsignedBigInteger('requested_by_admin_id')->nullable();
            $table->string('requested_by_name', 255)->nullable();
            $table->unsignedBigInteger('reviewed_by_hr_id')->nullable();
            $table->string('reviewed_by_name', 255)->nullable();
            $table->timestamp('reviewed_at')->nullable();
            $table->string('hr_remarks', 500)->nullable();
            $table->timestamps();
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

        $this->otherDepartment = Department::query()->create([
            'name' => 'City Mayor Office',
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

        $this->otherDeptAdmin = DepartmentAdmin::query()->create([
            'department_id' => $this->otherDepartment->id,
            'full_name' => 'CMO Admin',
            'username' => 'cmo_admin',
            'password' => 'secret',
            'must_change_password' => false,
        ]);

        $this->hrAccount = HRAccount::query()->create([
            'full_name' => 'HR Officer',
            'position' => 'HR Officer',
            'username' => 'hr_officer',
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
