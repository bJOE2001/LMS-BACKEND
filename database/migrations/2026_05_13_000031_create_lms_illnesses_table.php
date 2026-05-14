<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('tblIllnesses')) {
            Schema::create('tblIllnesses', function (Blueprint $table): void {
                $table->id();
                $table->string('name', 255)->unique();
                $table->boolean('is_inactive')->default(false);
                $table->timestamps();

                $table->index(['is_inactive', 'name'], 'IX_tblIllnesses_is_inactive_name');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('tblIllnesses');
    }
};
