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
        // 1. Move tblDtrRemarks to bio connection
        if (! Schema::connection('bio')->hasTable('tblDtrRemarks')) {
            Schema::connection('bio')->create('tblDtrRemarks', function (Blueprint $table): void {
                $table->id();
                $table->string('abbrv', 50)->nullable()->index('IX_tblDtrRemarks_abbrv');
                $table->string('description', 150);
                $table->integer('order_no')->default(0);
                $table->boolean('is_active')->default(true)->index('IX_tblDtrRemarks_is_active');
                $table->timestamps();
            });
        }

        if (DB::connection('bio')->table('tblDtrRemarks')->count() === 0) {
            if (Schema::hasTable('tblDtrRemarks')) {
                $existingRemarks = DB::table('tblDtrRemarks')->get();
                foreach ($existingRemarks as $row) {
                    $data = (array) $row;
                    unset($data['id']);
                    DB::connection('bio')->table('tblDtrRemarks')->insert($data);
                }
            } else {
                $defaultRemarks = [
                    ['abbrv' => 'O.B.', 'description' => 'OFFICIAL BUSINESS', 'order_no' => 1, 'is_active' => true],
                    ['abbrv' => 'O.F.', 'description' => 'ON FIELD', 'order_no' => 2, 'is_active' => true],
                    ['abbrv' => null, 'description' => 'OTHERS', 'order_no' => 3, 'is_active' => true],
                ];

                foreach ($defaultRemarks as $remark) {
                    DB::connection('bio')->table('tblDtrRemarks')->insert(array_merge($remark, [
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]));
                }
            }
        }

        // Drop from LMS_DB if it exists there
        if (Schema::hasTable('tblDtrRemarks')) {
            Schema::dropIfExists('tblDtrRemarks');
        }

        // 2. Move tblHolidays to bio connection
        if (! Schema::connection('bio')->hasTable('tblHolidays')) {
            Schema::connection('bio')->create('tblHolidays', function (Blueprint $table): void {
                $table->id();
                $table->date('holiday_date')->index('IX_tblHolidays_holiday_date');
                $table->string('name', 150);
                $table->string('type', 50)->default('REGULAR');
                $table->boolean('is_recurring')->default(false);
                $table->boolean('is_active')->default(true)->index('IX_tblHolidays_is_active');
                $table->string('description', 255)->nullable();
                $table->timestamps();
            });
        }

        if (DB::connection('bio')->table('tblHolidays')->count() === 0) {
            if (Schema::hasTable('tblHolidays')) {
                $existingHolidays = DB::table('tblHolidays')->get();
                foreach ($existingHolidays as $row) {
                    $data = (array) $row;
                    unset($data['id']);
                    DB::connection('bio')->table('tblHolidays')->insert($data);
                }
            } else {
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

        // Drop from LMS_DB if it exists there
        if (Schema::hasTable('tblHolidays')) {
            Schema::dropIfExists('tblHolidays');
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (! Schema::hasTable('tblDtrRemarks')) {
            Schema::create('tblDtrRemarks', function (Blueprint $table): void {
                $table->id();
                $table->string('abbrv', 50)->nullable()->index('IX_tblDtrRemarks_abbrv');
                $table->string('description', 150);
                $table->integer('order_no')->default(0);
                $table->boolean('is_active')->default(true)->index('IX_tblDtrRemarks_is_active');
                $table->timestamps();
            });

            if (Schema::connection('bio')->hasTable('tblDtrRemarks')) {
                $remarks = DB::connection('bio')->table('tblDtrRemarks')->get();
                foreach ($remarks as $row) {
                    $data = (array) $row;
                    unset($data['id']);
                    DB::table('tblDtrRemarks')->insert($data);
                }
                Schema::connection('bio')->dropIfExists('tblDtrRemarks');
            }
        }

        if (! Schema::hasTable('tblHolidays')) {
            Schema::create('tblHolidays', function (Blueprint $table): void {
                $table->id();
                $table->date('holiday_date')->index('IX_tblHolidays_holiday_date');
                $table->string('name', 150);
                $table->string('type', 50)->default('REGULAR');
                $table->boolean('is_recurring')->default(false);
                $table->boolean('is_active')->default(true)->index('IX_tblHolidays_is_active');
                $table->string('description', 255)->nullable();
                $table->timestamps();
            });

            if (Schema::connection('bio')->hasTable('tblHolidays')) {
                $holidays = DB::connection('bio')->table('tblHolidays')->get();
                foreach ($holidays as $row) {
                    $data = (array) $row;
                    unset($data['id']);
                    DB::table('tblHolidays')->insert($data);
                }
                Schema::connection('bio')->dropIfExists('tblHolidays');
            }
        }
    }
};
