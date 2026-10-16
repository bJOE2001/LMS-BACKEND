<?php

namespace Tests\Feature;

use App\Models\DailyTimeRecord;
use App\Models\DtrRemark;
use App\Models\Holiday;
use App\Models\HRAccount;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class AttendanceRemarkAndHolidayTest extends TestCase
{
    protected function getHrUser(): HRAccount
    {
        $hrUser = HRAccount::first();
        if (! $hrUser) {
            $hrUser = HRAccount::create([
                'username' => 'hr_test_admin',
                'full_name' => 'HR Test Admin',
                'password' => bcrypt('password123'),
                'must_change_password' => false,
            ]);
        } else {
            $hrUser->must_change_password = false;
            $hrUser->save();
        }

        return $hrUser;
    }

    public function test_can_fetch_dtr_remarks_options(): void
    {
        $hrUser = $this->getHrUser();
        Sanctum::actingAs($hrUser, ['*']);

        DtrRemark::firstOrCreate(
            ['abbrv' => 'TEST_REM'],
            ['description' => 'Test Remark', 'order_no' => 99, 'is_active' => true]
        );

        $response = $this->getJson('/api/dtr-remarks/options');

        $response->assertStatus(200)
            ->assertJsonStructure(['remarks']);
    }

    public function test_can_crud_dtr_remarks_as_hr(): void
    {
        $hrUser = $this->getHrUser();
        Sanctum::actingAs($hrUser, ['*']);

        // Create
        $createResponse = $this->postJson('/api/hr/dtr-remarks', [
            'abbrv' => 'TEST_CRU',
            'description' => 'Test CRUD Remark',
            'order_no' => 10,
            'is_active' => true,
        ]);

        $createResponse->assertStatus(201)
            ->assertJsonPath('remark.abbrv', 'TEST_CRU');

        $remarkId = $createResponse->json('remark.id');

        // Update (POST /api/hr/dtr-remarks/{id}/update)
        $updateResponse = $this->postJson("/api/hr/dtr-remarks/{$remarkId}/update", [
            'abbrv' => 'TEST_UPD',
            'description' => 'Updated CRUD Remark',
            'order_no' => 11,
            'is_active' => false,
        ]);

        $updateResponse->assertStatus(200)
            ->assertJsonPath('remark.abbrv', 'TEST_UPD');

        // Delete (POST /api/hr/dtr-remarks/{id}/delete)
        $deleteResponse = $this->postJson("/api/hr/dtr-remarks/{$remarkId}/delete");

        $deleteResponse->assertStatus(200)
            ->assertJsonPath('message', 'Remark deleted successfully.');

        $this->assertDatabaseMissing('tblDtrRemarks', ['id' => $remarkId]);
    }

    public function test_can_fetch_holidays_options(): void
    {
        $hrUser = $this->getHrUser();
        Sanctum::actingAs($hrUser, ['*']);

        Holiday::firstOrCreate(
            ['holiday_date' => '2026-12-30'],
            [
                'name' => 'Rizal Day Test',
                'type' => 'REGULAR',
                'is_recurring' => true,
                'is_active' => true,
            ]
        );

        $response = $this->getJson('/api/holidays/options?year=2026');

        $response->assertStatus(200)
            ->assertJsonStructure(['holidays']);
    }

    public function test_can_crud_holidays_as_hr(): void
    {
        $hrUser = $this->getHrUser();
        Sanctum::actingAs($hrUser, ['*']);

        // Create
        $createResponse = $this->postJson('/api/hr/holidays', [
            'name' => 'City Foundation Day Test',
            'holiday_date' => '2026-06-15',
            'type' => 'SPECIAL_NON_WORKING',
            'is_recurring' => false,
            'is_active' => true,
        ]);

        $createResponse->assertStatus(201)
            ->assertJsonPath('holiday.name', 'City Foundation Day Test');

        $holidayId = $createResponse->json('holiday.id');

        // Update (POST /api/hr/holidays/{id}/update)
        $updateResponse = $this->postJson("/api/hr/holidays/{$holidayId}/update", [
            'name' => 'City Foundation Day Renamed',
            'holiday_date' => '2026-06-16',
            'type' => 'SPECIAL_WORKING',
            'is_recurring' => true,
            'is_active' => true,
        ]);

        $updateResponse->assertStatus(200)
            ->assertJsonPath('holiday.name', 'City Foundation Day Renamed');

        // Delete (POST /api/hr/holidays/{id}/delete)
        $deleteResponse = $this->postJson("/api/hr/holidays/{$holidayId}/delete");

        $deleteResponse->assertStatus(200)
            ->assertJsonPath('message', 'Holiday deleted successfully.');

        $this->assertDatabaseMissing('tblHolidays', ['id' => $holidayId]);
    }

    public function test_override_dtr_with_remark(): void
    {
        $hrUser = $this->getHrUser();
        Sanctum::actingAs($hrUser, ['*']);

        $response = $this->postJson('/api/attendance/dtr/override', [
            'employee_control_no' => '022936',
            'date' => '2026-10-14',
            'am_arrival' => '08:00 AM',
            'am_departure' => '12:00 PM',
            'pm_arrival' => '01:00 PM',
            'pm_departure' => '05:00 PM',
            'remark' => 'O.B.',
        ]);

        $response->assertStatus(200)
            ->assertJsonPath('message', 'Attendance time overridden successfully.')
            ->assertJsonPath('dtr.status', DailyTimeRecord::STATUS_OFFICIAL_BUSINESS)
            ->assertJsonPath('dtr.adjustment_remarks', 'O.B.');

        $this->assertDatabaseHas('tblDailyTimeRecords', [
            'employee_control_no' => '022936',
            'record_date' => '2026-10-14',
            'status' => DailyTimeRecord::STATUS_OFFICIAL_BUSINESS,
            'adjustment_remarks' => 'O.B.',
        ], 'bio');
    }
}
