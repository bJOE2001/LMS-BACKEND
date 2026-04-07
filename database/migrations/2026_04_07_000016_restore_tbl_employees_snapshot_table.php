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
                $table->dateTime('from_date')->nullable();
                $table->dateTime('to_date')->nullable();
                $table->boolean('is_active')->default(false);
                $table->string('activity_status', 16)->nullable();
                $table->timestamp('last_synced_at')->nullable();
                $table->timestamps();

                $table->index('office');
                $table->index('status');
                $table->index('is_active');
                $table->index(['office', 'control_no'], 'IX_tblEmployees_office_control_no');
            });

            return;
        }

        Schema::table('tblEmployees', function (Blueprint $table): void {
            if (!Schema::hasColumn('tblEmployees', 'from_date')) {
                $table->dateTime('from_date')->nullable()->after('birth_date');
            }

            if (!Schema::hasColumn('tblEmployees', 'to_date')) {
                $table->dateTime('to_date')->nullable()->after('from_date');
            }

            if (!Schema::hasColumn('tblEmployees', 'is_active')) {
                $table->boolean('is_active')->default(false)->after('to_date');
            }

            if (!Schema::hasColumn('tblEmployees', 'activity_status')) {
                $table->string('activity_status', 16)->nullable()->after('is_active');
            }

            if (!Schema::hasColumn('tblEmployees', 'last_synced_at')) {
                $table->timestamp('last_synced_at')->nullable()->after('activity_status');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (!Schema::hasTable('tblEmployees')) {
            return;
        }

        Schema::table('tblEmployees', function (Blueprint $table): void {
            if (Schema::hasColumn('tblEmployees', 'last_synced_at')) {
                $table->dropColumn('last_synced_at');
            }

            if (Schema::hasColumn('tblEmployees', 'activity_status')) {
                $table->dropColumn('activity_status');
            }

            if (Schema::hasColumn('tblEmployees', 'is_active')) {
                $table->dropColumn('is_active');
            }

            if (Schema::hasColumn('tblEmployees', 'to_date')) {
                $table->dropColumn('to_date');
            }

            if (Schema::hasColumn('tblEmployees', 'from_date')) {
                $table->dropColumn('from_date');
            }
        });
    }
};
