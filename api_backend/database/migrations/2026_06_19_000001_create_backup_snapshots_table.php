<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('backup_snapshots', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('label', 120);
            $table->uuid('created_by')->nullable();
            $table->foreign('created_by')->references('id')->on('users')->nullOnDelete();
            $table->unsignedBigInteger('size_bytes')->default(0);
            // ready | restoring | restore_failed
            $table->string('status', 20)->default('ready');
            $table->text('notes')->nullable();
            // { counts: { users: 5, plots: 12, … }, total_records: 17 }
            $table->json('metadata')->nullable();
            // Full serialised state of every app table
            $table->longText('snapshot_data');
            $table->timestamps();

            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('backup_snapshots');
    }
};
