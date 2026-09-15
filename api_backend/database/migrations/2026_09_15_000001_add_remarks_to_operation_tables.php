<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('irrigation_operations') && ! Schema::hasColumn('irrigation_operations', 'remarks')) {
            Schema::table('irrigation_operations', function (Blueprint $table): void {
                $table->text('remarks')->nullable()->after('water_quantity');
            });
        }

        if (Schema::hasTable('fertilization_operations') && ! Schema::hasColumn('fertilization_operations', 'remarks')) {
            Schema::table('fertilization_operations', function (Blueprint $table): void {
                $table->text('remarks')->nullable()->after('quantity_applied');
            });
        }

        if (Schema::hasTable('harvest_operations') && ! Schema::hasColumn('harvest_operations', 'remarks')) {
            Schema::table('harvest_operations', function (Blueprint $table): void {
                $table->text('remarks')->nullable()->after('quantity_harvested');
            });
        }
    }

    public function down(): void
    {
        foreach (['irrigation_operations', 'fertilization_operations', 'harvest_operations'] as $tableName) {
            if (Schema::hasTable($tableName) && Schema::hasColumn($tableName, 'remarks')) {
                Schema::table($tableName, function (Blueprint $table): void {
                    $table->dropColumn('remarks');
                });
            }
        }
    }
};
