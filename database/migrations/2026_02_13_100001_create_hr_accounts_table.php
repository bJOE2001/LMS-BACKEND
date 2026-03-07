<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * LOCAL DEVELOPMENT ONLY — runs against LMS_DB.
 * Does NOT connect to pmis2003 or BIOASD.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('hr_accounts', function (Blueprint $table) {
            $table->id();
            $table->string('full_name');
            $table->string('username')->unique();
            $table->string('password');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('hr_accounts');
    }
};
