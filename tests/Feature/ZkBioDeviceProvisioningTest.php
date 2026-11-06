<?php

namespace Tests\Feature;

use App\Models\BiometricDevice;
use App\Models\Department;
use App\Models\HRAccount;
use Illuminate\Http\Client\Request as HttpClientRequest;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ZkBioDeviceProvisioningTest extends TestCase
{
    private function authenticateHr(): void
    {
        $hr = new HRAccount([
            'username' => 'hr_provisioning_tester',
            'full_name' => 'HR Provisioning Tester',
            'must_change_password' => false,
        ]);
        $hr->id = 1;

        Sanctum::actingAs($hr);
    }

    private function configureMockZkBio(): void
    {
        config([
            'services.zkbio.base_url' => 'http://zkbio-mock.test',
            'services.zkbio.api_user' => 'mock_admin',
            'services.zkbio.api_pass' => 'mock_secret',
        ]);
    }

    public function test_registering_adms_device_syncs_terminal_to_zkbio_and_keeps_dhcp_ip_null(): void
    {
        $this->authenticateHr();
        $this->configureMockZkBio();

        $sn = 'MOCK_ADMS_SN_'.uniqid();
        $department = Department::query()->create([
            'name' => 'TEST CICTMO PROVISIONING '.uniqid(),
            'code' => 'TCP'.random_int(100, 999),
            'acronym' => 'CICTMO',
            'is_inactive' => false,
        ]);

        Http::preventStrayRequests();
        Http::fake([
            'http://zkbio-mock.test/api-token-auth/' => Http::response(['token' => 'fake-zk-token'], 200),
            'http://zkbio-mock.test/personnel/api/areas/*' => Http::response([
                'data' => [
                    ['id' => 1, 'area_code' => '1', 'area_name' => 'Default', 'is_default' => true],
                    ['id' => 2, 'area_code' => '2', 'area_name' => 'CICTMO', 'is_default' => false],
                ],
            ], 200),
            'http://zkbio-mock.test/iclock/api/terminals/?*' => Http::response(['data' => []], 200),
            'http://zkbio-mock.test/iclock/api/terminals/' => Http::response([
                'id' => 77,
                'sn' => $sn,
                'alias' => 'CICTMO Lobby',
                'ip_address' => '0.0.0.0',
                'area' => 2,
            ], 201),
        ]);

        try {
            $response = $this->postJson('/api/attendance/devices', [
                'device_name' => 'CICTMO Lobby',
                'serial_number' => $sn,
                'department_id' => $department->id,
                'communication_mode' => 'ADMS',
                'zkbio_area_id' => 2,
                'ip_address' => '',
                'is_primary' => false,
            ]);

            $response->assertStatus(201);
            $response->assertJsonPath('zkbio_synced', true);
            $response->assertJsonPath('device.zkbio_area_id', 2);
            $response->assertJsonPath('device.ip_address', null);
            $response->assertJsonPath('device.status', 'OFFLINE');

            Http::assertSent(function (HttpClientRequest $req) use ($sn): bool {
                return $req->method() === 'POST'
                    && $req->url() === 'http://zkbio-mock.test/iclock/api/terminals/'
                    && $req['sn'] === $sn
                    && $req['alias'] === 'CICTMO Lobby'
                    && $req['ip_address'] === '0.0.0.0'
                    && $req['area'] === 2;
            });
        } finally {
            BiometricDevice::query()->where('serial_number', $sn)->delete();
            $department->delete();
        }
    }

    public function test_registering_adms_device_auto_creates_zkbio_area_when_office_has_no_matching_area(): void
    {
        $this->authenticateHr();
        $this->configureMockZkBio();

        $sn = 'MOCK_AUTO_AREA_SN_'.uniqid();
        $acronym = 'TOAA'.random_int(10, 99);
        $department = Department::query()->create([
            'name' => 'TEST OFFICE AUTO AREA '.uniqid(),
            'code' => $acronym,
            'acronym' => $acronym,
            'is_inactive' => false,
        ]);

        Http::preventStrayRequests();
        Http::fake([
            'http://zkbio-mock.test/api-token-auth/' => Http::response(['token' => 'fake-zk-token'], 200),
            'http://zkbio-mock.test/personnel/api/areas/?*' => Http::response([
                'data' => [
                    ['id' => 1, 'area_code' => '1', 'area_name' => 'Default', 'is_default' => true],
                    ['id' => 2, 'area_code' => '2', 'area_name' => 'CICTMO', 'is_default' => false],
                    ['id' => 4, 'area_code' => '3', 'area_name' => 'CHRMO', 'is_default' => false],
                ],
            ], 200),
            'http://zkbio-mock.test/personnel/api/areas/' => Http::response([
                'id' => 95,
                'area_code' => '4',
                'area_name' => $acronym,
            ], 201),
            'http://zkbio-mock.test/iclock/api/terminals/?*' => Http::response(['data' => []], 200),
            'http://zkbio-mock.test/iclock/api/terminals/' => Http::response([
                'id' => 88,
                'sn' => $sn,
                'alias' => $acronym,
                'ip_address' => '0.0.0.0',
                'area' => 95,
            ], 201),
        ]);

        try {
            $response = $this->postJson('/api/attendance/devices', [
                'device_name' => $acronym,
                'serial_number' => $sn,
                'department_id' => $department->id,
                'communication_mode' => 'ADMS',
                'zkbio_area_id' => null,
                'is_primary' => false,
            ]);

            $response->assertStatus(201);
            $response->assertJsonPath('zkbio_synced', true);
            $response->assertJsonPath('device.zkbio_area_id', 95);

            Http::assertSent(function (HttpClientRequest $req) use ($acronym): bool {
                return $req->method() === 'POST'
                    && $req->url() === 'http://zkbio-mock.test/personnel/api/areas/'
                    && $req['area_code'] === '4'
                    && $req['area_name'] === $acronym;
            });

            Http::assertSent(function (HttpClientRequest $req) use ($sn): bool {
                return $req->method() === 'POST'
                    && $req->url() === 'http://zkbio-mock.test/iclock/api/terminals/'
                    && $req['sn'] === $sn
                    && $req['area'] === 95;
            });
        } finally {
            BiometricDevice::query()->where('serial_number', $sn)->delete();
            $department->delete();
        }
    }

    public function test_registering_adms_device_rejects_prohibited_default_area_1(): void
    {
        $this->authenticateHr();
        $this->configureMockZkBio();

        $sn = 'MOCK_REJECT_AREA1_'.uniqid();
        $department = Department::query()->create([
            'name' => 'TEST REJECT AREA1 '.uniqid(),
            'code' => 'TRA'.random_int(100, 999),
            'acronym' => 'TRA',
            'is_inactive' => false,
        ]);

        Http::preventStrayRequests();

        try {
            $response = $this->postJson('/api/attendance/devices', [
                'device_name' => 'Invalid Area Device',
                'serial_number' => $sn,
                'department_id' => $department->id,
                'communication_mode' => 'ADMS',
                'zkbio_area_id' => 1,
                'is_primary' => false,
            ]);

            $response->assertStatus(422);
            $this->assertDatabaseMissing('tblBiometricDevices', [
                'serial_number' => $sn,
            ], 'bio');
        } finally {
            BiometricDevice::query()->where('serial_number', $sn)->delete();
            $department->delete();
        }
    }

    public function test_updating_and_deleting_adms_device_syncs_changes_to_zkbio(): void
    {
        $this->authenticateHr();
        $this->configureMockZkBio();

        $sn = 'MOCK_UPD_DEL_SN_'.uniqid();
        $department = Department::query()->create([
            'name' => 'TEST UPD DEL OFFICE '.uniqid(),
            'code' => 'TUD'.random_int(100, 999),
            'acronym' => 'CICTMO',
            'is_inactive' => false,
        ]);

        $device = BiometricDevice::query()->create([
            'device_name' => 'Initial Alias',
            'serial_number' => $sn,
            'department_id' => $department->id,
            'department_name' => $department->name,
            'communication_mode' => 'ADMS',
            'zkbio_area_id' => 2,
            'is_primary' => false,
            'is_active' => true,
            'status' => 'OFFLINE',
        ]);

        Http::preventStrayRequests();
        Http::fake([
            'http://zkbio-mock.test/api-token-auth/' => Http::response(['token' => 'fake-zk-token'], 200),
            'http://zkbio-mock.test/personnel/api/areas/*' => Http::response([
                'data' => [
                    ['id' => 2, 'area_code' => '2', 'area_name' => 'CICTMO', 'is_default' => false],
                    ['id' => 4, 'area_code' => '3', 'area_name' => 'CHRMO', 'is_default' => false],
                ],
            ], 200),
            'http://zkbio-mock.test/iclock/api/terminals/?*' => Http::response([
                'data' => [
                    ['id' => 55, 'sn' => $sn, 'alias' => 'Initial Alias', 'ip_address' => '0.0.0.0', 'area' => 2],
                ],
            ], 200),
            'http://zkbio-mock.test/iclock/api/terminals/55/' => function (HttpClientRequest $req) {
                if ($req->method() === 'PATCH') {
                    return Http::response(['id' => 55, 'alias' => 'Updated Alias', 'area' => 4], 200);
                }
                if ($req->method() === 'DELETE') {
                    return Http::response(null, 204);
                }

                return Http::response([], 405);
            },
        ]);

        try {
            $updateResp = $this->postJson("/api/attendance/devices/{$device->id}/update", [
                'device_name' => 'Updated Alias',
                'department_id' => $department->id,
                'communication_mode' => 'ADMS',
                'zkbio_area_id' => 4,
                'is_primary' => false,
            ]);

            $updateResp->assertStatus(200);
            $updateResp->assertJsonPath('zkbio_synced', true);
            $updateResp->assertJsonPath('device.zkbio_area_id', 4);

            Http::assertSent(function (HttpClientRequest $req): bool {
                return $req->method() === 'PATCH'
                    && $req->url() === 'http://zkbio-mock.test/iclock/api/terminals/55/'
                    && $req['alias'] === 'Updated Alias'
                    && $req['area'] === 4;
            });

            $deleteResp = $this->postJson("/api/attendance/devices/{$device->id}/delete");
            $deleteResp->assertStatus(200);
            $deleteResp->assertJsonPath('zkbio_deleted', true);

            Http::assertSent(function (HttpClientRequest $req): bool {
                return $req->method() === 'DELETE'
                    && $req->url() === 'http://zkbio-mock.test/iclock/api/terminals/55/';
            });
        } finally {
            BiometricDevice::query()->where('serial_number', $sn)->delete();
            $department->delete();
        }
    }
}
