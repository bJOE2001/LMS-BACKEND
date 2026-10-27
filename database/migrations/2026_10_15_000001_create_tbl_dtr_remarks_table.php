<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        if (! Schema::connection('bio')->hasTable('tblDtrRemarks')) {
            Schema::connection('bio')->create('tblDtrRemarks', function (Blueprint $table): void {
                $table->id();
                $table->string('abbrv', 50)->nullable()->index('IX_tblDtrRemarks_abbrv');
                $table->string('description', 150);
                $table->integer('order_no')->default(0);
                $table->boolean('is_active')->default(true)->index('IX_tblDtrRemarks_is_active');
                $table->timestamps();
            });

            // Seed default official remarks matching HR requirements
            $defaultRemarks = [
                ['abbrv' => 'O.B.', 'description' => 'OFFICIAL BUSINESS', 'order_no' => 1, 'is_active' => true],
                ['abbrv' => 'O.F.', 'description' => 'ON FIELD', 'order_no' => 2, 'is_active' => true],
                ['abbrv' => null, 'description' => 'OTHERS', 'order_no' => 3, 'is_active' => true],
            ];

            foreach ($defaultRemarks as $remark) {
                DB::connection('bio')->table('tblDtrRemarks')->insert(array_merge($remark, [
                    'created_at' => now(),
                    'updated_at' => now(),
                ]));
            }
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::connection('bio')->dropIfExists('tblDtrRemarks');
    }
};
