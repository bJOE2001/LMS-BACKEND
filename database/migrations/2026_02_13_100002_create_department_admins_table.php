<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * LOCAL DEVELOPMENT ONLY — runs against LMS_DB.
 * Exactly one admin per department (unique on department_id).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('department_admins', function (Blueprint $table) {
            $table->id();
            $table->foreignId('department_id')
                ->unique()
                ->constrained('departments')
                ->cascadeOnDelete();
            $table->string('full_name');
            $table->string('username')->unique();
            $table->string('password');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('department_admins');
    }
};
