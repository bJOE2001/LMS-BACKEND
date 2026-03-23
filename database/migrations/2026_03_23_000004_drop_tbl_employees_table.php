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
        // Remove foreign keys that still point to tblEmployees.
        if (Schema::hasTable('tblLeaveBalances')) {
            try {
                Schema::table('tblLeaveBalances', function (Blueprint $table): void {
                    $table->dropForeign(['employee_control_no']);
                });
            } catch (\Throwable) {
                // Foreign key may already be removed in some environments.
            }
        }

        if (Schema::hasTable('tblCOCApplications')) {
            try {
                Schema::table('tblCOCApplications', function (Blueprint $table): void {
                    $table->dropForeign(['employee_control_no']);
                });
            } catch (\Throwable) {
                // Foreign key may already be removed in some environments.
            }
        }

        Schema::dropIfExists('tblEmployees');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (!Schema::hasTable('tblEmployees')) {
            Schema::create('tblEmployees', function (Blueprint $table): void {
                $table->string('control_no')->primary();
                $table->string('surname')->nullable();
                $table->string('firstname')->nullable();
                $table->string('middlename')->nullable();
                $table->string('office')->nullable();
                $table->string('status')->nullable();
                $table->string('designation')->nullable();
                $table->decimal('rate_mon', 10, 2)->nullable();
                $table->date('birth_date')->nullable();
                $table->timestamps();

                $table->index('office');
                $table->index('status');
                $table->index(['office', 'control_no'], 'IX_tblEmployees_office_control_no');
            });
        }

        if (Schema::hasTable('tblLeaveBalances') && Schema::hasTable('tblEmployees')) {
            try {
                Schema::table('tblLeaveBalances', function (Blueprint $table): void {
                    $table->foreign('employee_control_no')
                        ->references('control_no')
                        ->on('tblEmployees')
                        ->cascadeOnDelete();
                });
            } catch (\Throwable) {
                // Foreign key may already exist.
            }
        }

        if (Schema::hasTable('tblCOCApplications') && Schema::hasTable('tblEmployees')) {
            try {
                Schema::table('tblCOCApplications', function (Blueprint $table): void {
                    $table->foreign('employee_control_no')
                        ->references('control_no')
                        ->on('tblEmployees')
                        ->noActionOnDelete();
                });
            } catch (\Throwable) {
                // Foreign key may already exist.
            }
        }
    }
};
