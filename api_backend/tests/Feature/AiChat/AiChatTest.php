<?php

declare(strict_types=1);

namespace Tests\Feature\AiChat;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

final class AiChatTest extends TestCase
{
    use RefreshDatabase;

    public function test_chat_returns_assistant_reply(): void
    {
        $this->actingAsRole('manager');

        Http::fake([
            'openrouter.ai/*' => Http::response([
                'choices' => [
                    ['message' => ['role' => 'assistant', 'content' => 'You have 0 active plots.']],
                ],
            ], 200),
        ]);

        $res = $this->postJson('/api/v1/ai/chat', [
            'messages' => [
                ['role' => 'user', 'content' => 'How many plots do we have?'],
            ],
            'locale' => 'en',
        ])->assertOk();

        $this->assertSame('You have 0 active plots.', $res->json('data.reply'));
        $this->assertNotEmpty($res->json('data.conversation_id'));
    }

    public function test_chat_requires_authentication(): void
    {
        $this->postJson('/api/v1/ai/chat', [
            'messages' => [['role' => 'user', 'content' => 'Hello']],
        ])->assertUnauthorized();
    }
}
