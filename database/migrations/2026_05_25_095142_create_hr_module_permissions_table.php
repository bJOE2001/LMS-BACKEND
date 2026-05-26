<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        if (! Schema::hasTable('tblHRModulePermissions')) {
            Schema::create('tblHRModulePermissions', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('hr_account_id')
                    ->constrained('tblHRAccounts')
                    ->cascadeOnDelete();
                $table->string('module_key', 64);
                $table->foreignId('granted_by_hr_account_id')
                    ->nullable()
                    ->constrained('tblHRAccounts')
                    ->noActionOnDelete();
                $table->timestamps();

                $table->unique(
                    ['hr_account_id', 'module_key'],
                    'UQ_tblHRModulePermissions_account_module'
                );
                $table->index('module_key', 'IX_tblHRModulePermissions_module_key');
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('tblHRModulePermissions');
    }
};
