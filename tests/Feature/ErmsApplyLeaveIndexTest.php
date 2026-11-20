<?php

namespace Tests\Feature;

use App\Models\Department;
use App\Models\EmployeeDepartmentAssignment;
use App\Models\HrisEmployee;
use App\Models\Leave\LeaveType;
use App\Services\WorkScheduleService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class ErmsApplyLeaveIndexTest extends TestCase
{
    private const ERMS_API_KEY = 'test-erms-secret-key';

    private Department $department;

    protected function setUp(): void
    {
        parent::setUp();

        Cache::flush();
        HrisEmployee::flushCache();
        app(WorkScheduleService::class)->clearScheduleCache();

        Config::set('erms.keys', [self::ERMS_API_KEY]);
        Config::set('erms.trusted_origins', []);
        Config::set('erms.allowed_ips', []);

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

        DB::purge('hr');
        DB::purge('sqlsrv');

        $this->createSchemas();
        $this->seedFixtures();
    }

    public function test_erms_apply_leave_returns_employee_context_and_allowed_leave_types_for_regular_employee(): void
    {
        $response = $this->withHeaders([
            'X-ERMS-KEY' => self::ERMS_API_KEY,
        ])->getJson('/api/erms/apply-leave?employee_control_no=00123');

        $response->assertOk()
            ->assertJsonPath('employee_control_no', '00123')
            ->assertJsonPath('employment_status', 'Regular')
            ->assertJsonPath('employment_status_key', 'regular')
            ->assertJsonPath('ui_variant', 'default')
            ->assertJsonPath('employee.control_no', '00123')
            ->assertJsonPath('employee.firstname', 'Juan')
            ->assertJsonPath('employee.surname', 'Dela Cruz')
            ->assertJsonPath('employee.designation', 'ADMINISTRATIVE OFFICER IV')
            ->assertJsonPath('employee.status', 'Regular')
            ->assertJsonPath('employee.is_contractual', false)
            ->assertJsonPath('applications', []);

        $leaveTypeNames = collect($response->json('leave_types'))->pluck('name')->all();

        $this->assertSame(['Vacation Leave', 'Wellness Leave'], $leaveTypeNames);
    }

    public function test_erms_leave_records_returns_contractual_context_and_filters_ineligible_leave_types(): void
    {
        $response = $this->withHeaders([
            'X-ERMS-KEY' => self::ERMS_API_KEY,
        ])->getJson('/api/erms/leave-records?employee_control_no=00456');

        $response->assertOk()
            ->assertJsonPath('employee_control_no', '00456')
            ->assertJsonPath('employment_status', 'Contractual')
            ->assertJsonPath('employment_status_key', 'contractual')
            ->assertJsonPath('ui_variant', 'contractual')
            ->assertJsonPath('employee.control_no', '00456')
            ->assertJsonPath('employee.status', 'Contractual')
            ->assertJsonPath('employee.is_contractual', true);

        $leaveTypeNames = collect($response->json('leave_types'))->pluck('name')->all();

        $this->assertSame(['Wellness Leave'], $leaveTypeNames);
    }

    public function test_erms_apply_leave_rejects_unpulled_employee(): void
    {
        $this->withHeaders([
            'X-ERMS-KEY' => self::ERMS_API_KEY,
        ])->getJson('/api/erms/apply-leave?employee_control_no=00789')
            ->assertStatus(403)
            ->assertJsonPath('can_access_leave_modules', false);
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

        Schema::connection('sqlsrv')->create('tblDepartmentAdmins', function (Blueprint $table): void {
            $table->increments('id');
            $table->unsignedInteger('department_id');
            $table->string('employee_control_no', 64)->nullable();
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
            $table->string('department_acronym', 50)->nullable();
            $table->timestamps();
        });

        Schema::connection('sqlsrv')->create('tblLeaveTypes', function (Blueprint $table): void {
            $table->increments('id');
            $table->string('name', 120);
            $table->string('category', 30);
            $table->decimal('accrual_rate', 8, 2)->nullable();
            $table->integer('accrual_day_of_month')->nullable();
            $table->integer('max_days')->nullable();
            $table->boolean('is_credit_based')->default(false);
            $table->boolean('resets_yearly')->default(false);
            $table->boolean('requires_documents')->default(false);
            $table->text('allowed_status')->nullable();
            $table->text('description')->nullable();
            $table->timestamps();
        });

        Schema::connection('sqlsrv')->create('tblLeaveApplications', function (Blueprint $table): void {
            $table->increments('id');
            $table->unsignedInteger('applicant_admin_id')->nullable();
            $table->string('employee_control_no', 64);
            $table->string('erms_control_no', 64)->nullable();
            $table->string('employee_name', 255)->nullable();
            $table->unsignedInteger('leave_type_id');
            $table->date('start_date');
            $table->date('end_date');
            $table->decimal('total_days', 8, 3);
            $table->string('status', 40);
            $table->unsignedInteger('admin_id')->nullable();
            $table->unsignedInteger('hr_id')->nullable();
            $table->timestamps();
        });

        Schema::connection('sqlsrv')->create('tblWorkScheduleSettings', function (Blueprint $table): void {
            $table->increments('id');
            $table->string('setting_key', 64)->unique();
            $table->string('work_start_time', 10);
            $table->string('work_end_time', 10);
            $table->string('break_start_time', 10)->nullable();
            $table->string('break_end_time', 10)->nullable();
            $table->decimal('working_hours_per_day', 6, 2);
            $table->decimal('whole_day_leave_deduction', 6, 3);
            $table->decimal('half_day_leave_deduction', 6, 3);
            $table->text('notes')->nullable();
            $table->unsignedInteger('updated_by_hr_account_id')->nullable();
            $table->timestamps();
        });

        Schema::connection('sqlsrv')->create('tblEmployeeWorkScheduleOverrides', function (Blueprint $table): void {
            $table->increments('id');
            $table->string('employee_control_no', 64);
            $table->string('employee_name', 255)->nullable();
            $table->string('office', 255)->nullable();
            $table->string('designation', 255)->nullable();
            $table->string('status', 64)->nullable();
            $table->string('work_start_time', 10);
            $table->string('work_end_time', 10);
            $table->string('break_start_time', 10)->nullable();
            $table->string('break_end_time', 10)->nullable();
            $table->decimal('working_hours_per_day', 6, 2);
            $table->decimal('whole_day_leave_deduction', 6, 3);
            $table->decimal('half_day_leave_deduction', 6, 3);
            $table->text('notes')->nullable();
            $table->boolean('is_active')->default(true);
            $table->unsignedInteger('updated_by_hr_account_id')->nullable();
            $table->timestamps();
        });
    }

    private function seedFixtures(): void
    {
        $this->department = Department::query()->create([
            'name' => 'Office of the City Human Resource Management Officer',
            'is_inactive' => false,
        ]);

        EmployeeDepartmentAssignment::query()->create([
            'employee_control_no' => '00123',
            'department_id' => $this->department->id,
            'assigned_at' => now(),
            'surname' => 'Dela Cruz',
            'firstname' => 'Juan',
            'middlename' => 'Santos',
        ]);

        EmployeeDepartmentAssignment::query()->create([
            'employee_control_no' => '00456',
            'department_id' => $this->department->id,
            'assigned_at' => now(),
            'surname' => 'Reyes',
            'firstname' => 'Maria',
            'middlename' => 'Clara',
        ]);

        LeaveType::query()->create([
            'name' => 'Vacation Leave',
            'category' => LeaveType::CATEGORY_ACCRUED,
            'is_credit_based' => true,
            'requires_documents' => false,
            'allowed_status' => [LeaveType::EMPLOYMENT_STATUS_REGULAR],
        ]);

        LeaveType::query()->create([
            'name' => 'Wellness Leave',
            'category' => LeaveType::CATEGORY_RESETTABLE,
            'max_days' => 5,
            'is_credit_based' => false,
            'requires_documents' => false,
            'allowed_status' => null,
        ]);

        LeaveType::query()->create([
            'name' => 'MC06 Leave',
            'category' => LeaveType::CATEGORY_RESETTABLE,
            'max_days' => 3,
            'is_credit_based' => false,
            'requires_documents' => false,
            'allowed_status' => [LeaveType::EMPLOYMENT_STATUS_REGULAR],
        ]);

        $hrisRows = [
            [
                'control_no' => '00123',
                'surname' => 'Dela Cruz',
                'firstname' => 'Juan',
                'middlename' => 'Santos',
                'designation' => 'ADMINISTRATIVE OFFICER IV',
                'office' => $this->department->name,
                'officeAcronym' => 'CHRMO',
                'status' => 'REGULAR',
            ],
            [
                'control_no' => '00456',
                'surname' => 'Reyes',
                'firstname' => 'Maria',
                'middlename' => 'Clara',
                'designation' => 'PROJECT ASSISTANT',
                'office' => $this->department->name,
                'officeAcronym' => 'CHRMO',
                'status' => 'CONTRACTUAL',
            ],
            [
                'control_no' => '00789',
                'surname' => 'Santos',
                'firstname' => 'Pedro',
                'middlename' => 'Cruz',
                'designation' => 'CLERK II',
                'office' => $this->department->name,
                'officeAcronym' => 'CHRMO',
                'status' => 'REGULAR',
            ],
        ];

        $version = (string) Cache::get('hris_employees.cache_version', 'v4');
        Cache::put("hris_employees.v5.{$version}.all.all", $hrisRows, now()->addMinutes(5));

        foreach ($hrisRows as $row) {
            $raw = $row['control_no'];
            $unpadded = ltrim($raw, '0');
            $padded = str_pad($unpadded, max(6, strlen($raw)), '0', STR_PAD_LEFT);
            $lookupValues = collect([$raw, $unpadded, $padded])
                ->unique()
                ->sort()
                ->values()
                ->all();

            Cache::put(
                "hris_employees.v5.{$version}.directory_lookup.".md5(implode('|', $lookupValues)).'.all',
                [$row],
                now()->addMinutes(5)
            );
        }
    }
}
