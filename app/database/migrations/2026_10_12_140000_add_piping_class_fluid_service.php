<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('piping_classes', function (Blueprint $table): void {
            $table->string('fluid_service_category', 32)->nullable();
        });
        DB::statement("ALTER TABLE piping_classes ADD CONSTRAINT piping_classes_fluid_service_valid CHECK (fluid_service_category IS NULL OR fluid_service_category IN ('NORMAL', 'CATEGORY_D', 'CATEGORY_M', 'ELEVATED_TEMPERATURE', 'HIGH_PRESSURE', 'HIGH_PURITY'))");
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE piping_classes DROP CONSTRAINT piping_classes_fluid_service_valid');
        Schema::table('piping_classes', fn (Blueprint $table) => $table->dropColumn('fluid_service_category'));
    }
};
