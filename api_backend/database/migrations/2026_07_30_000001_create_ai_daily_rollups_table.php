<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Pre-aggregated one-row-per (operation type, plot, campaign, day) rollup.
 *
 * Period questions ("quantités d'eau de la parcelle B12 du 15 au 30 juin")
 * are the single most frequent shape of question the assistant gets. Reading
 * them from this table instead of scanning raw operation rows makes the answer
 * both fast and — more importantly — *stable*: units are normalised once, at
 * build time, so two identical questions can never produce two different
 * totals.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_daily_rollups', function (Blueprint $table): void {
            $table->id();
            $table->string('op_type', 20);
            $table->uuid('plot_id');
            // '' instead of NULL: Postgres unique indexes treat NULLs as
            // distinct, which would allow duplicate rows for uncampaigned ops.
            $table->string('campaign_key', 64)->default('');
            $table->date('day');

            $table->unsignedInteger('ops')->default(0);
            // Irrigation quantities are stored here already normalised to m³.
            $table->decimal('qty', 18, 4)->default(0);
            $table->decimal('cost', 18, 4)->default(0);

            $table->unsignedInteger('missing_qty')->default(0);
            $table->unsignedInteger('missing_price')->default(0);
            $table->unsignedSmallInteger('unit_variants')->default(1);

            $table->timestamps();

            $table->unique(['op_type', 'plot_id', 'campaign_key', 'day'], 'ai_daily_rollups_grain_unique');
            $table->index(['op_type', 'day'], 'ai_daily_rollups_type_day_idx');
            $table->index(['op_type', 'plot_id', 'day'], 'ai_daily_rollups_type_plot_day_idx');
        });

        Schema::create('ai_rollup_state', function (Blueprint $table): void {
            $table->string('op_type', 20)->primary();
            // Signature of the source table (row count + latest write). A change
            // means the rollup is stale and which days to rebuild.
            $table->unsignedBigInteger('source_rows')->default(0);
            $table->timestamp('source_max_updated_at')->nullable();
            $table->timestamp('built_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_rollup_state');
        Schema::dropIfExists('ai_daily_rollups');
    }
};