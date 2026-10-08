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
        if (Schema::hasTable('tblDepartments')) {
            Schema::table('tblDepartments', function (Blueprint $table): void {
                if (! Schema::hasColumn('tblDepartments', 'code')) {
                    $table->string('code', 20)->nullable()->after('name');
                }
                if (! Schema::hasColumn('tblDepartments', 'acronym')) {
                    $table->string('acronym', 50)->nullable()->after('code');
                }
            });

            // Institutional mappings aligned 100% with HRIS yOffice
            $mappings = [
                1 => ['code' => '00038', 'acronym' => 'CPO'],
                2 => ['code' => '00037', 'acronym' => 'CTC'],
                3 => ['code' => '08901', 'acronym' => 'CMO-NLA'],
                4 => ['code' => '08832', 'acronym' => 'CACCO'],
                5 => ['code' => '08839', 'acronym' => 'CMO-ADMIN'],
                6 => ['code' => '08825', 'acronym' => 'CAGRO'],
                7 => ['code' => '08823', 'acronym' => 'CARCHO'],
                8 => ['code' => '08821', 'acronym' => 'CASSO'],
                9 => ['code' => '08831', 'acronym' => 'CBO'],
                10 => ['code' => '08828', 'acronym' => 'CCRO'],
                11 => ['code' => '08818', 'acronym' => 'CDRRMO'],
                12 => ['code' => '08836', 'acronym' => 'CEEO'],
                13 => ['code' => '08822', 'acronym' => 'CEO'],
                14 => ['code' => '08826', 'acronym' => 'CENRO'],
                15 => ['code' => '08833', 'acronym' => 'CGSO'],
                16 => ['code' => '08838', 'acronym' => 'CHO'],
                17 => ['code' => '08820', 'acronym' => 'CHLMO'],
                18 => ['code' => '08835', 'acronym' => 'CHRMO'],
                19 => ['code' => '08933', 'acronym' => 'CICTMO'],
                20 => ['code' => '08834', 'acronym' => 'CLO'],
                21 => ['code' => '08829', 'acronym' => 'CMO'],
                22 => ['code' => '08903', 'acronym' => 'NLA'],
                23 => ['code' => '08830', 'acronym' => 'CPDO'],
                24 => ['code' => '08931', 'acronym' => 'CPESCDO'],
                25 => ['code' => '08824', 'acronym' => 'CSWDO'],
                26 => ['code' => '08932', 'acronym' => 'CTACHMO'],
                27 => ['code' => '08837', 'acronym' => 'CTO'],
                28 => ['code' => '08827', 'acronym' => 'CVO'],
                29 => ['code' => '08840', 'acronym' => 'CVMO'],
                30 => ['code' => '08930', 'acronym' => 'CVMO-SP'],
                31 => ['code' => '08816', 'acronym' => 'SP-LEG'],
                32 => ['code' => '08817', 'acronym' => 'SP-SEC'],
                33 => ['code' => '08966', 'acronym' => 'TCTS'],
            ];

            foreach ($mappings as $id => $data) {
                DB::table('tblDepartments')->where('id', $id)->update($data);
            }
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (Schema::hasTable('tblDepartments')) {
            Schema::table('tblDepartments', function (Blueprint $table): void {
                if (Schema::hasColumn('tblDepartments', 'acronym')) {
                    $table->dropColumn('acronym');
                }
                if (Schema::hasColumn('tblDepartments', 'code')) {
                    $table->dropColumn('code');
                }
            });
        }
    }
};
