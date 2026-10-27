<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        if (! Schema::connection('bio')->hasTable('tblHolidays')) {
            Schema::connection('bio')->create('tblHolidays', function (Blueprint $table): void {
                $table->id();
                $table->date('holiday_date')->index('IX_tblHolidays_holiday_date');
                $table->string('name', 150);
                $table->string('type', 50)->default('REGULAR'); // REGULAR, SPECIAL_NON_WORKING, SPECIAL_WORKING
                $table->boolean('is_recurring')->default(false);
                $table->boolean('is_active')->default(true)->index('IX_tblHolidays_is_active');
                $table->string('description', 255)->nullable();
                $table->timestamps();
            });

            $year = now()->year;
            $defaultHolidays = [
                ['holiday_date' => "{$year}-01-01", 'name' => "New Year's Day", 'type' => 'REGULAR', 'is_recurring' => true],
                ['holiday_date' => "{$year}-04-09", 'name' => 'Araw ng Kagitingan', 'type' => 'REGULAR', 'is_recurring' => true],
                ['holiday_date' => "{$year}-05-01", 'name' => 'Labor Day', 'type' => 'REGULAR', 'is_recurring' => true],
                ['holiday_date' => "{$year}-06-12", 'name' => 'Independence Day', 'type' => 'REGULAR', 'is_recurring' => true],
                ['holiday_date' => "{$year}-08-31", 'name' => 'National Heroes Day', 'type' => 'REGULAR', 'is_recurring' => false],
                ['holiday_date' => "{$year}-11-01", 'name' => "All Saints' Day", 'type' => 'SPECIAL_NON_WORKING', 'is_recurring' => true],
                ['holiday_date' => "{$year}-11-02", 'name' => "All Souls' Day", 'type' => 'SPECIAL_NON_WORKING', 'is_recurring' => true],
                ['holiday_date' => "{$year}-11-30", 'name' => 'Bonifacio Day', 'type' => 'REGULAR', 'is_recurring' => true],
                ['holiday_date' => "{$year}-12-08", 'name' => 'Feast of the Immaculate Conception', 'type' => 'SPECIAL_NON_WORKING', 'is_recurring' => true],
                ['holiday_date' => "{$year}-12-25", 'name' => 'Christmas Day', 'type' => 'REGULAR', 'is_recurring' => true],
                ['holiday_date' => "{$year}-12-30", 'name' => 'Rizal Day', 'type' => 'REGULAR', 'is_recurring' => true],
                ['holiday_date' => "{$year}-12-31", 'name' => 'Last Day of the Year', 'type' => 'SPECIAL_NON_WORKING', 'is_recurring' => true],
            ];

            foreach ($defaultHolidays as $holiday) {
                DB::connection('bio')->table('tblHolidays')->insert(array_merge($holiday, [
                    'is_active' => true,
                    'description' => null,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]));
            }
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::connection('bio')->dropIfExists('tblHolidays');
    }
};
