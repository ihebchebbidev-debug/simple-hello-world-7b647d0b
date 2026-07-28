<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_feedback', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('user_id')->nullable();
            $table->foreign('user_id')->references('id')->on('users')->nullOnDelete();
            $table->uuid('conversation_id')->nullable();
            // Client-generated message id so the same reply can only be rated once per user.
            $table->string('message_client_id', 64);
            // 'up' | 'down'
            $table->string('rating', 8);
            $table->string('locale', 10)->nullable();
            // Free-form comment (optional; e.g. "invented plot name").
            $table->text('comment')->nullable();
            // Snapshot of the exchange for offline review / guardrail tuning.
            $table->text('question')->nullable();
            $table->text('answer')->nullable();
            // Structured tags such as ['hallucination','stale_data','wrong_unit'].
            $table->json('tags')->nullable();
            $table->timestamps();

            $table->unique(['user_id', 'message_client_id']);
            $table->index(['rating', 'created_at']);
            $table->index('conversation_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_feedback');
    }
};
