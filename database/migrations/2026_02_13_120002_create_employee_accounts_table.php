<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Employee login accounts — created when HR generates credentials.
 * username = birthdate (Y-m-d), password = hashed(lastname + birthdate).
 * must_change_password forces password change on first login.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('employee_accounts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_id')
                ->constrained('employees')
                ->cascadeOnDelete();
            $table->string('username')->unique();
            $table->string('password');
            $table->boolean('must_change_password')->default(true);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('employee_accounts');
    }
};
