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
        Schema::dropIfExists('tblDepartmentHeads');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::create('tblDepartmentHeads', function (Blueprint $table) {
            $table->id();
            $table->foreignId('department_id')
                ->unique()
                ->constrained('tblDepartments')
                ->cascadeOnDelete();
            $table->string('full_name');
            $table->string('position')->nullable();
            $table->timestamps();
        });
    }
};

