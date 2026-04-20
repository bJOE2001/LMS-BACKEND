<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (
            !Schema::hasColumn('tblEmployeeDepartmentAssignments', 'surname')
            || !Schema::hasColumn('tblEmployeeDepartmentAssignments', 'firstname')
            || !Schema::hasColumn('tblEmployeeDepartmentAssignments', 'middlename')
            || !Schema::hasColumn('tblEmployeeDepartmentAssignments', 'department_acronym')
        ) {
            Schema::table('tblEmployeeDepartmentAssignments', function (Blueprint $table): void {
                if (!Schema::hasColumn('tblEmployeeDepartmentAssignments', 'surname')) {
                    $table->string('surname')->nullable();
                }

                if (!Schema::hasColumn('tblEmployeeDepartmentAssignments', 'firstname')) {
                    $table->string('firstname')->nullable();
                }

                if (!Schema::hasColumn('tblEmployeeDepartmentAssignments', 'middlename')) {
                    $table->string('middlename')->nullable();
                }

                if (!Schema::hasColumn('tblEmployeeDepartmentAssignments', 'department_acronym')) {
                    $table->string('department_acronym')->nullable();
                }
            });
        }
    }

    public function down(): void
    {
        Schema::table('tblEmployeeDepartmentAssignments', function (Blueprint $table): void {
            if (Schema::hasColumn('tblEmployeeDepartmentAssignments', 'department_acronym')) {
                $table->dropColumn('department_acronym');
            }

            if (Schema::hasColumn('tblEmployeeDepartmentAssignments', 'middlename')) {
                $table->dropColumn('middlename');
            }

            if (Schema::hasColumn('tblEmployeeDepartmentAssignments', 'firstname')) {
                $table->dropColumn('firstname');
            }

            if (Schema::hasColumn('tblEmployeeDepartmentAssignments', 'surname')) {
                $table->dropColumn('surname');
            }
        });
    }
};
