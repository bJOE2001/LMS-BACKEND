<?php

namespace Tests\Feature;

use App\Models\HrisEmployee;
use App\Models\Leave\COCApplication;
use App\Models\Leave\LeaveBalance;
use App\Models\Leave\LeaveType;
use App\Services\Leave\CocLedgerService;
use App\Services\Leave\LeaveCreditService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class CocLeaveBalanceSyncTest extends TestCase
{
    private const CTO_LEAVE_TYPE_ID = 8;

    protected function setUp(): void
    {
        parent::setUp();

        Cache::flush();
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

        DB::purge('hr');
        DB::purge('sqlsrv');

        $this->createSchemas();
        $this->seedFixtures();
    }

    public function test_coc_ledger_sync_populates_employee_name_and_leave_type_name_on_new_cto_balance(): void
    {
        $this->createApprovedCocApplication('011790', 'MAHUSAY, JOGRAD', 8.0);

        app(CocLedgerService::class)->syncEmployeeLedger('011790', self::CTO_LEAVE_TYPE_ID);

        $balance = LeaveBalance::query()
            ->where('employee_control_no', '011790')
            ->where('leave_type_id', self::CTO_LEAVE_TYPE_ID)
            ->first();

        $this->assertNotNull($balance);
        $this->assertSame('MAHUSAY, JOGRAD', $balance->employee_name);
        $this->assertSame('CTO Leave', $balance->leave_type_name);
        $this->assertSame('1.000', number_format((float) $balance->balance, 3, '.', ''));
    }

    public function test_coc_ledger_sync_backfills_null_employee_name_and_leave_type_name_on_existing_cto_balance(): void
    {
        $this->createApprovedCocApplication('011790', 'MAHUSAY, JOGRAD', 8.0);

        LeaveBalance::query()->create([
            'employee_control_no' => '011790',
            'employee_name' => null,
            'leave_type_id' => self::CTO_LEAVE_TYPE_ID,
            'leave_type_name' => null,
            'balance' => 1.000,
            'year' => (int) now()->year,
        ]);

        app(CocLedgerService::class)->syncEmployeeLedger('011790', self::CTO_LEAVE_TYPE_ID);

        $balance = LeaveBalance::query()
            ->where('employee_control_no', '011790')
            ->where('leave_type_id', self::CTO_LEAVE_TYPE_ID)
            ->first();

        $this->assertNotNull($balance);
        $this->assertSame('MAHUSAY, JOGRAD', $balance->employee_name);
        $this->assertSame('CTO Leave', $balance->leave_type_name);
        $this->assertSame('1.000', number_format((float) $balance->balance, 3, '.', ''));
    }

    public function test_leave_credit_sync_employee_cto_balance_populates_and_backfills_metadata(): void
    {
        $this->createApprovedCocApplication('011790', 'MAHUSAY, JOGRAD', 8.0);

        $employeeResolver = static fn (string $controlNo): object => (object) [
            'control_no' => $controlNo,
            'surname' => 'MAHUSAY',
            'firstname' => 'JOGRAD',
            'middlename' => '',
        ];

        $syncedBalance = app(LeaveCreditService::class)->syncEmployeeCtoBalance(
            '011790',
            false,
            null,
            null,
            $employeeResolver
        );

        $balance = LeaveBalance::query()
            ->where('employee_control_no', '011790')
            ->where('leave_type_id', self::CTO_LEAVE_TYPE_ID)
            ->first();

        $this->assertSame(1.0, $syncedBalance);
        $this->assertNotNull($balance);
        $this->assertSame('MAHUSAY, JOGRAD', $balance->employee_name);
        $this->assertSame('CTO Leave', $balance->leave_type_name);

        // Clear metadata and re-sync without an explicit employeeResolver (falls back to HrisEmployee).
        $balance->employee_name = null;
        $balance->leave_type_name = null;
        $balance->save();

        app(LeaveCreditService::class)->syncEmployeeCtoBalance('011790');

        $balance->refresh();
        $this->assertSame('MAHUSAY, JOGRAD', $balance->employee_name);
        $this->assertSame('CTO Leave', $balance->leave_type_name);
    }

    public function test_cto_sync_still_populates_leave_type_name_when_hris_employee_is_missing(): void
    {
        $this->createApprovedCocApplication('999999', 'UNKNOWN', 8.0);

        app(CocLedgerService::class)->syncEmployeeLedger('999999', self::CTO_LEAVE_TYPE_ID);

        $balance = LeaveBalance::query()
            ->where('employee_control_no', '999999')
            ->where('leave_type_id', self::CTO_LEAVE_TYPE_ID)
            ->first();

        $this->assertNotNull($balance);
        $this->assertNull($balance->employee_name);
        $this->assertSame('CTO Leave', $balance->leave_type_name);
        $this->assertSame('1.000', number_format((float) $balance->balance, 3, '.', ''));
    }

    private function createApprovedCocApplication(string $controlNo, string $employeeName, float $creditedHours): void
    {
        COCApplication::query()->create([
            'employee_control_no' => $controlNo,
            'employee_name' => $employeeName,
            'status' => COCApplication::STATUS_APPROVED,
            'cto_leave_type_id' => self::CTO_LEAVE_TYPE_ID,
            'cto_credited_days' => round($creditedHours / 8.0, 2),
            'cto_credited_at' => now()->subDay(),
            'reviewed_at' => now()->subDay(),
            'total_minutes' => (int) round($creditedHours * 60),
            'credited_hours' => $creditedHours,
        ]);
    }

    private function createSchemas(): void
    {
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

        Schema::connection('sqlsrv')->create('tblLeaveBalances', function (Blueprint $table): void {
            $table->increments('id');
            $table->string('employee_control_no', 64);
            $table->string('employee_name')->nullable();
            $table->unsignedInteger('leave_type_id');
            $table->string('leave_type_name')->nullable();
            $table->decimal('balance', 9, 3)->default(0);
            $table->date('last_accrual_date')->nullable();
            $table->unsignedSmallInteger('year')->nullable();
            $table->timestamps();
        });

        Schema::connection('sqlsrv')->create('tblCOCApplications', function (Blueprint $table): void {
            $table->increments('id');
            $table->string('employee_control_no', 64);
            $table->string('employee_name')->nullable();
            $table->string('status', 16)->default('PENDING');
            $table->timestamp('reviewed_at')->nullable();
            $table->unsignedInteger('cto_leave_type_id')->nullable();
            $table->decimal('cto_credited_days', 8, 2)->nullable();
            $table->timestamp('cto_credited_at')->nullable();
            $table->unsignedInteger('total_minutes')->default(0);
            $table->decimal('credited_hours', 8, 2)->nullable();
            $table->timestamps();
        });

        Schema::connection('sqlsrv')->create('tblCOCApplicationRows', function (Blueprint $table): void {
            $table->increments('id');
            $table->unsignedInteger('coc_application_id');
            $table->string('employee_control_no', 64);
            $table->unsignedInteger('line_no')->default(1);
            $table->date('overtime_date')->nullable();
            $table->text('nature_of_overtime')->nullable();
            $table->string('time_from', 5)->nullable();
            $table->string('time_to', 5)->nullable();
            $table->unsignedInteger('minutes')->default(0);
            $table->unsignedInteger('break_minutes')->default(0);
            $table->string('credit_category', 16)->nullable();
            $table->decimal('credit_multiplier', 4, 2)->nullable();
            $table->timestamps();
        });

        Schema::connection('sqlsrv')->create('tblLeaveApplications', function (Blueprint $table): void {
            $table->increments('id');
            $table->string('employee_control_no', 64)->nullable();
            $table->unsignedInteger('leave_type_id');
            $table->decimal('total_days', 5, 2)->default(0);
            $table->decimal('deductible_days', 5, 2)->nullable();
            $table->decimal('cto_deducted_hours', 8, 2)->nullable();
            $table->string('pay_mode', 8)->default('WP');
            $table->boolean('is_monetization')->default(false);
            $table->string('status')->default('PENDING_ADMIN');
            $table->timestamp('hr_approved_at')->nullable();
            $table->timestamps();
        });

        Schema::connection('sqlsrv')->create('tblCOCLedgerEntries', function (Blueprint $table): void {
            $table->increments('id');
            $table->string('employee_control_no', 64);
            $table->unsignedInteger('leave_type_id');
            $table->unsignedInteger('sequence_no');
            $table->string('entry_type', 24);
            $table->string('reference_type', 32)->nullable();
            $table->unsignedBigInteger('coc_application_id')->nullable();
            $table->unsignedBigInteger('leave_application_id')->nullable();
            $table->decimal('hours', 10, 2);
            $table->decimal('balance_after_hours', 10, 2);
            $table->timestamp('effective_at')->nullable();
            $table->date('expires_on')->nullable();
            $table->text('remarks')->nullable();
            $table->timestamps();
        });
    }

    private function seedFixtures(): void
    {
        DB::connection('sqlsrv')->table('tblLeaveTypes')->insert([
            'id' => self::CTO_LEAVE_TYPE_ID,
            'name' => 'CTO Leave',
            'category' => LeaveType::CATEGORY_EVENT,
            'is_credit_based' => true,
            'resets_yearly' => false,
            'requires_documents' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $hrisRows = [
            [
                'control_no' => '011790',
                'surname' => 'MAHUSAY',
                'firstname' => 'JOGRAD',
                'middlename' => '',
                'designation' => 'ADMINISTRATIVE OFFICER',
                'office' => 'CHRMO',
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

        // Cache empty directory lookups for missing control numbers so SQLite HR connection is not queried for xPersonal.
        foreach (['999999'] as $missingRaw) {
            $unpadded = ltrim($missingRaw, '0');
            $padded = str_pad($unpadded, max(6, strlen($missingRaw)), '0', STR_PAD_LEFT);
            $lookupValues = collect([$missingRaw, $unpadded, $padded])
                ->unique()
                ->sort()
                ->values()
                ->all();

            Cache::put(
                "hris_employees.v5.{$version}.directory_lookup.".md5(implode('|', $lookupValues)).'.all',
                [],
                now()->addMinutes(5)
            );
        }
    }
}
