<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * LOCAL DEVELOPMENT ONLY — runs against LMS_DB.
 * This is the local employees table, separate from the remote
 * pmis2003.vwActive view used by HrisEmployee.
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('employees', function (Blueprint $table) {
            $table->id();
            $table->foreignId('department_id')
                ->constrained('departments')
                ->cascadeOnDelete();
            $table->string('first_name');
            $table->string('last_name');
            $table->string('position')->nullable();
            $table->enum('status', ['CO-TERMINOUS', 'ELECTIVE', 'CASUAL', 'REGULAR']);
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('employees');
    }
};
