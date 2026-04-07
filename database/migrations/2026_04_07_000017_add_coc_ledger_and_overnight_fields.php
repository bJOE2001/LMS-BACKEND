<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tblCOCApplicationRows', function (Blueprint $table): void {
            if (!Schema::hasColumn('tblCOCApplicationRows', 'is_overnight')) {
                $table->boolean('is_overnight')->default(false)->after('time_to');
            }
        });

        if (!Schema::hasTable('tblCOCLedgerEntries')) {
            Schema::create('tblCOCLedgerEntries', function (Blueprint $table): void {
                $table->id();
                $table->string('employee_control_no');
                $table->foreignId('leave_type_id')
                    ->constrained('tblLeaveTypes')
                    ->noActionOnDelete();
                $table->unsignedInteger('sequence_no');
                $table->string('entry_type', 24);
                $table->string('reference_type', 32)->nullable();
                $table->unsignedBigInteger('coc_application_id')->nullable();
                $table->unsignedBigInteger('leave_application_id')->nullable();
                $table->decimal('hours', 10, 2);
                $table->decimal('balance_after_hours', 10, 2);
                $table->timestamp('effective_at');
                $table->date('expires_on')->nullable();
                $table->text('remarks')->nullable();
                $table->timestamps();

                $table->index(['employee_control_no', 'leave_type_id'], 'ix_tblcocledgerentries_employee_leave_type');
                $table->index(['employee_control_no', 'effective_at'], 'ix_tblcocledgerentries_employee_effective_at');
                $table->index(['leave_type_id', 'entry_type'], 'ix_tblcocledgerentries_leave_type_entry_type');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('tblCOCLedgerEntries')) {
            Schema::drop('tblCOCLedgerEntries');
        }

        Schema::table('tblCOCApplicationRows', function (Blueprint $table): void {
            if (Schema::hasColumn('tblCOCApplicationRows', 'is_overnight')) {
                $table->dropColumn('is_overnight');
            }
        });
    }
};
