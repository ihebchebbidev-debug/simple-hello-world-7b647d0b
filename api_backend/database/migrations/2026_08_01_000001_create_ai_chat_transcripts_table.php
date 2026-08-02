<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Raw, verbatim log of every AI chat exchange (question + answer, including
 * failures). Written server-side so nothing depends on the client persisting
 * anything. Read by the hidden /chat inspection page.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_chat_transcripts', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('conversation_id')->nullable()->index();
            $table->uuid('user_id')->nullable();
            $table->string('user_label', 190)->nullable();
            $table->string('locale', 10)->nullable();
            $table->boolean('streamed')->default(false);
            // 'ok' | 'error'
            $table->string('status', 16)->default('ok');
            $table->string('error_code', 64)->nullable();
            $table->longText('question')->nullable();
            $table->longText('answer')->nullable();
            $table->integer('duration_ms')->nullable();
            $table->string('ip', 64)->nullable();
            $table->timestamps();

            $table->index(['created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_chat_transcripts');
    }
};
