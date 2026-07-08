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
        if (! Schema::hasTable('tblSpecialPrivilegeReasons')) {
            Schema::create('tblSpecialPrivilegeReasons', function (Blueprint $table): void {
                $table->id();
                $table->text('description');
                $table->boolean('is_inactive')->default(false);
                $table->timestamps();

                $table->index(['is_inactive'], 'IX_tblSpecialPrivilegeReasons_is_inactive');
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('tblSpecialPrivilegeReasons');
    }
};
