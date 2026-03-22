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
        Schema::create('tblRecycleBin', function (Blueprint $table): void {
            $table->id();
            $table->string('entity_type', 100);
            $table->string('table_name', 128);
            $table->string('record_primary_key', 128)->default('id');
            $table->string('record_primary_value', 191);
            $table->string('record_title')->nullable();
            $table->string('deleted_by_type', 100)->nullable();
            $table->string('deleted_by_id', 191)->nullable();
            $table->string('deleted_by_name')->nullable();
            $table->string('delete_source', 191)->nullable();
            $table->text('delete_reason')->nullable();
            $table->longText('record_snapshot');
            $table->timestamp('deleted_at')->useCurrent();
            $table->timestamps();

            $table->index(['table_name', 'record_primary_value'], 'IX_tblRecycleBin_table_name_record_primary_value');
            $table->index(['deleted_by_type', 'deleted_by_id'], 'IX_tblRecycleBin_deleted_by');
            $table->index('deleted_at', 'IX_tblRecycleBin_deleted_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('tblRecycleBin');
    }
};
