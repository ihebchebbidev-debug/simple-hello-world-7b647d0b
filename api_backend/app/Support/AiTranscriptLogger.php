<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\AiChatTranscript;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Writes a verbatim record of every AI chat exchange (successful or failed).
 * Never throws: logging must never break a reply.
 */
final class AiTranscriptLogger
{
    /**
     * @param  array<int, array{role?: string, content?: string}>  $messages
     */
    public static function record(
        Request $request,
        array $messages,
        string $locale,
        ?string $conversationId,
        bool $streamed,
        string $status,
        ?string $answer,
        ?string $errorCode = null,
        ?int $durationMs = null,
    ): void {
        try {
            $user = $request->user();

            AiChatTranscript::create([
                'conversation_id' => $conversationId,
                'user_id'         => $user?->id,
                'user_label'      => $user?->email ?? $user?->name ?? null,
                'locale'          => $locale,
                'streamed'        => $streamed,
                'status'          => $status,
                'error_code'      => $errorCode,
                'question'        => self::lastUserMessage($messages),
                'answer'          => $answer,
                'duration_ms'     => $durationMs,
                'ip'              => $request->ip(),
            ]);
        } catch (Throwable $e) {
            Log::warning('ai.transcript.log_failed', ['message' => $e->getMessage()]);
        }
    }

    /** @param array<int, array{role?: string, content?: string}> $messages */
    private static function lastUserMessage(array $messages): ?string
    {
        for ($i = count($messages) - 1; $i >= 0; $i--) {
            $m = $messages[$i];
            if (($m['role'] ?? '') === 'user') {
                return (string) ($m['content'] ?? '');
            }
        }
        return null;
    }
}
