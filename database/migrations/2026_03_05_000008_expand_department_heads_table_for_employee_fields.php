<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        if (!Schema::hasTable('tblDepartmentHeads')) {
            return;
        }

        $columnsToAdd = [
            'control_no' => !Schema::hasColumn('tblDepartmentHeads', 'control_no'),
            'surname' => !Schema::hasColumn('tblDepartmentHeads', 'surname'),
            'firstname' => !Schema::hasColumn('tblDepartmentHeads', 'firstname'),
            'middlename' => !Schema::hasColumn('tblDepartmentHeads', 'middlename'),
            'office' => !Schema::hasColumn('tblDepartmentHeads', 'office'),
            'status' => !Schema::hasColumn('tblDepartmentHeads', 'status'),
            'designation' => !Schema::hasColumn('tblDepartmentHeads', 'designation'),
            'rate_mon' => !Schema::hasColumn('tblDepartmentHeads', 'rate_mon'),
        ];

        if (!in_array(true, $columnsToAdd, true)) {
            return;
        }

        Schema::table('tblDepartmentHeads', function (Blueprint $table) use ($columnsToAdd): void {
            if ($columnsToAdd['control_no']) {
                $table->string('control_no')->nullable();
            }
            if ($columnsToAdd['surname']) {
                $table->string('surname')->nullable();
            }
            if ($columnsToAdd['firstname']) {
                $table->string('firstname')->nullable();
            }
            if ($columnsToAdd['middlename']) {
                $table->string('middlename')->nullable();
            }
            if ($columnsToAdd['office']) {
                $table->string('office')->nullable();
            }
            if ($columnsToAdd['status']) {
                $table->string('status')->nullable();
            }
            if ($columnsToAdd['designation']) {
                $table->string('designation')->nullable();
            }
            if ($columnsToAdd['rate_mon']) {
                $table->decimal('rate_mon', 10, 2)->nullable();
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (!Schema::hasTable('tblDepartmentHeads')) {
            return;
        }

        $columnsToDrop = array_values(array_filter([
            Schema::hasColumn('tblDepartmentHeads', 'control_no') ? 'control_no' : null,
            Schema::hasColumn('tblDepartmentHeads', 'surname') ? 'surname' : null,
            Schema::hasColumn('tblDepartmentHeads', 'firstname') ? 'firstname' : null,
            Schema::hasColumn('tblDepartmentHeads', 'middlename') ? 'middlename' : null,
            Schema::hasColumn('tblDepartmentHeads', 'office') ? 'office' : null,
            Schema::hasColumn('tblDepartmentHeads', 'status') ? 'status' : null,
            Schema::hasColumn('tblDepartmentHeads', 'designation') ? 'designation' : null,
            Schema::hasColumn('tblDepartmentHeads', 'rate_mon') ? 'rate_mon' : null,
        ]));

        if ($columnsToDrop === []) {
            return;
        }

        Schema::table('tblDepartmentHeads', function (Blueprint $table) use ($columnsToDrop): void {
            $table->dropColumn($columnsToDrop);
        });
    }
};
