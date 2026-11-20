<?php

namespace Tests\Feature;

use App\Http\Controllers\Attendance\BiometricRegistrationController;
use App\Http\Controllers\EmployeeController;
use App\Models\Attendance\BiometricTransferLog;
use App\Models\Department;
use App\Models\HrisEmployee;
use App\Models\HrisOffice;
use App\Services\Attendance\BiometricTransferService;
use App\Services\Attendance\LanBioTemplateSyncService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class HrisOfficeTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Cache::flush();
        HrisOffice::flushCache();

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
        Config::set('database.connections.bio_sqlsrv', [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
        ]);

        DB::purge('hr');
        DB::purge('sqlsrv');
        DB::purge('bio');
        DB::purge('bio_sqlsrv');

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

        Schema::connection('bio')->create('tblBiometricTransferLogs', function (Blueprint $table): void {
            $table->increments('id');
            $table->string('employee_control_no', 50);
            $table->string('employee_name', 150)->nullable();
            $table->string('action_type', 30);
            $table->unsignedInteger('source_department_id')->nullable();
            $table->string('source_office_name', 150)->nullable();
            $table->string('source_device_sn', 100)->nullable();
            $table->unsignedInteger('target_department_id')->nullable();
            $table->string('target_office_name', 150)->nullable();
            $table->string('target_device_sn', 100)->nullable();
            $table->string('status', 30);
            $table->text('remarks')->nullable();
            $table->unsignedInteger('performed_by_user_id')->nullable();
            $table->string('performed_by_name', 150)->nullable();
            $table->timestamps();
        });
    }

    protected function tearDown(): void
    {
        HrisOffice::flushCache();
        Cache::flush();
        parent::tearDown();
    }

    public function test_it_reads_and_normalizes_yoffice_acronyms_with_active_priority(): void
    {
        DB::connection('hr')->table('yOffice')->insert([
            [
                'Codes' => '08832',
                'Descriptions' => 'OFFICE OF THE CITY ACCOUNTANT',
                'Abbr' => 'CACCO',
                'Active' => '1',
            ],
            [
                'Codes' => '00099',
                'Descriptions' => 'OFFICE OF THE CITY HUMAN RESOURCE MANAGEMENT OFFICER',
                'Abbr' => 'LEGACY-HR',
                'Active' => '0',
            ],
            [
                'Codes' => '08835',
                'Descriptions' => 'OFFICE OF THE CITY HUMAN RESOURCE MANAGEMENT OFFICER',
                'Abbr' => 'CHRMO',
                'Active' => '1',
            ],
            [
                'Codes' => '00037',
                'Descriptions' => 'CITY TRIAL COURT',
                'Abbr' => 'CITY TRIAL COURT',
                'Active' => '1',
            ],
            [
                'Codes' => '08954',
                'Descriptions' => 'CMO - ADMINISTRATIVE',
                'Abbr' => '',
                'Active' => '1',
            ],
        ]);

        $this->assertSame('CACCO', HrisOffice::acronymForName('  office of the city accountant  '));
        $this->assertSame('CHRMO', HrisOffice::acronymForName('OFFICE OF THE CITY HUMAN RESOURCE MANAGEMENT OFFICER'));
        $this->assertSame('CHRMO', HrisEmployee::officeAcronymByName('OFFICE OF THE CITY HUMAN RESOURCE MANAGEMENT OFFICER'));
        $this->assertSame('CACCO', HrisOffice::acronymForCode('08832'));
        $this->assertSame('CTC', HrisOffice::acronymForName('CITY TRIAL COURT'));
        $this->assertNull(HrisOffice::acronymForName('CMO - ADMINISTRATIVE'));
        $this->assertCount(4, HrisOffice::allCached(true));
        $this->assertCount(5, HrisOffice::allCached(false));
    }

    public function test_departments_endpoint_enriches_offices_with_yoffice_acronyms(): void
    {
        DB::connection('hr')->table('yOffice')->insert([
            [
                'Codes' => '08832',
                'Descriptions' => 'OFFICE OF THE CITY ACCOUNTANT',
                'Abbr' => 'CACCO',
                'Active' => '1',
            ],
            [
                'Codes' => '08933',
                'Descriptions' => 'OFFICE OF THE CITY INFORMATION AND COMMUNICATIONS TECHNOLOGY MANAGEMENT OFFICER',
                'Abbr' => 'CICTMO',
                'Active' => '1',
            ],
        ]);

        Department::query()->create([
            'name' => 'OFFICE OF THE CITY ACCOUNTANT',
            'is_inactive' => false,
        ]);
        Department::query()->create([
            'name' => 'OFFICE OF THE CITY INFORMATION AND COMMUNICATIONS TECHNOLOGY MANAGEMENT OFFICER',
            'is_inactive' => false,
        ]);
        $division = Department::query()->create([
            'name' => 'CICTMO - PROGRAMMER',
            'main_department_id' => 2,
            'is_inactive' => false,
        ]);

        $response = app(EmployeeController::class)->departments();
        $payload = $response->getData(true);

        $byName = collect($payload['departments'])->keyBy('name');

        $this->assertSame('CACCO', $byName['OFFICE OF THE CITY ACCOUNTANT']['office_acronym']);
        $this->assertSame('CACCO', $byName['OFFICE OF THE CITY ACCOUNTANT']['acronym']);
        $this->assertSame('CICTMO', $byName['OFFICE OF THE CITY INFORMATION AND COMMUNICATIONS TECHNOLOGY MANAGEMENT OFFICER']['office_acronym']);
        $this->assertNull($byName['CICTMO - PROGRAMMER']['office_acronym']);
        $this->assertSame('CICTMO - PROGRAMMER', HrisOffice::labelForDepartment($division));
    }

    public function test_transfer_logs_resolve_yoffice_acronyms_while_preserving_full_office_names(): void
    {
        DB::connection('hr')->table('yOffice')->insert([
            [
                'Codes' => '08835',
                'Descriptions' => 'OFFICE OF THE CITY HUMAN RESOURCE MANAGEMENT OFFICER',
                'Abbr' => 'CHRMO',
                'Active' => '1',
            ],
            [
                'Codes' => '08832',
                'Descriptions' => 'OFFICE OF THE CITY ACCOUNTANT',
                'Abbr' => 'CACCO',
                'Active' => '1',
            ],
        ]);

        $chrmo = Department::query()->create([
            'name' => 'OFFICE OF THE CITY HUMAN RESOURCE MANAGEMENT OFFICER',
            'is_inactive' => false,
        ]);
        $cacco = Department::query()->create([
            'name' => 'OFFICE OF THE CITY ACCOUNTANT',
            'is_inactive' => false,
        ]);

        BiometricTransferLog::query()->create([
            'employee_control_no' => '00123',
            'employee_name' => 'JANE DOE',
            'action_type' => BiometricTransferLog::ACTION_TRANSFER,
            'source_department_id' => $chrmo->id,
            'source_office_name' => 'CHRMO',
            'target_department_id' => $cacco->id,
            'target_office_name' => $cacco->name,
            'status' => BiometricTransferLog::STATUS_SUCCESS,
            'remarks' => 'Transferred',
        ]);

        $controller = new BiometricRegistrationController(
            $this->createMock(BiometricTransferService::class),
            $this->createMock(LanBioTemplateSyncService::class),
        );

        $response = $controller->transferLogs(Request::create('/api/biometric/transfer-logs', 'GET'));
        $payload = $response->getData(true);

        $this->assertCount(1, $payload['logs']);
        $this->assertSame('CHRMO', $payload['logs'][0]['source_office_acronym']);
        $this->assertSame('OFFICE OF THE CITY HUMAN RESOURCE MANAGEMENT OFFICER', $payload['logs'][0]['source_office_name']);
        $this->assertSame('CACCO', $payload['logs'][0]['target_office_acronym']);
        $this->assertSame('OFFICE OF THE CITY ACCOUNTANT', $payload['logs'][0]['target_office_name']);
    }

    public function test_it_falls_back_gracefully_when_hris_connection_fails(): void
    {
        Config::set('database.connections.hr', [
            'driver' => 'sqlite',
            'database' => 'Z:/non-existent-dir/missing.sqlite',
            'prefix' => '',
        ]);
        DB::purge('hr');

        $this->assertNull(HrisOffice::acronymForName('OFFICE OF THE CITY ACCOUNTANT'));
        $this->assertCount(0, HrisOffice::allCached());
    }
}
