<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Liquid fertilizers store their composition as a mass percentage, which is
 * useless for a kg-N/ha dose without the product density. Without this column
 * the assistant must refuse every dose question on a product sold in litres.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('fertilizers') || Schema::hasColumn('fertilizers', 'density_kg_per_l')) {
            return;
        }

        Schema::table('fertilizers', function (Blueprint $table): void {
            $table->decimal('density_kg_per_l', 6, 3)->nullable()->after('s_percent');
        });
    }

    public function down(): void
    {
        if (Schema::hasTable('fertilizers') && Schema::hasColumn('fertilizers', 'density_kg_per_l')) {
            Schema::table('fertilizers', function (Blueprint $table): void {
                $table->dropColumn('density_kg_per_l');
            });
        }
    }
};
