<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AiFeedback;
use App\Services\AiChat\AiChatService;
use App\Services\AiChat\AiDeadline;
use App\Services\AiChat\AiTrace;


use App\Support\AiTranscriptLogger;
use App\Support\Http\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

final class AiChatController extends Controller
{
    private ?AiChatService $aiChatInstance = null;

    /**
     * Resolved lazily: if the assistant cannot be constructed (e.g. no
     * OpenRouter key configured), the failure must surface as a typed AI
     * error inside the request handlers instead of a generic 500 thrown
     * during container resolution.
     */
    private function aiChat(): AiChatService
    {
        return $this->aiChatInstance ??= app(AiChatService::class);
    }


    /**
     * Split a "code: message" runtime exception into (code, http_status, user_message).
     * Falls back to a generic error when the message has no known prefix.
     *
     * @return array{code: string, status: int, message: string}
     */
    private static function classifyError(string $raw): array
    {
        $code = 'ai_error';
        if (preg_match('/^([a-z_]+):/', $raw, $m) === 1) {
            $code = $m[1];
        } elseif (stripos($raw, 'openrouter api key') !== false) {
            // Thrown by OpenRouterKeyPool when the environment has no usable key.
            $code = 'config_missing';
        }

        if ($code === 'config_missing') {
            return ['code' => 'config_missing', 'status' => 503, 'message' => 'The assistant is not configured: no OpenRouter API key is set on the server.'];
        }


        return match ($code) {
            'circuit_open'    => ['code' => 'circuit_open',    'status' => 503, 'message' => 'Assistant paused after repeated upstream failures. Retrying shortly.'],
            'rate_limited'    => ['code' => 'rate_limited',    'status' => 429, 'message' => 'Upstream is rate-limiting requests. Please wait a few seconds and try again.'],
            'quota_exceeded'  => ['code' => 'quota_exceeded',  'status' => 402, 'message' => 'The AI provider quota is exhausted. Update the API keys or top up credit.'],
            'upstream_auth'   => ['code' => 'upstream_auth',   'status' => 502, 'message' => 'The AI provider rejected our credentials. Rotate the API key.'],
            'upstream_error'  => ['code' => 'upstream_error',  'status' => 502, 'message' => 'The AI provider returned an error. Please retry.'],
            'model_not_found' => ['code' => 'model_not_found', 'status' => 502, 'message' => 'The configured AI model is unavailable. Update OPENROUTER_MODEL to a valid slug.'],
            'timeout'         => ['code' => 'timeout',         'status' => 504, 'message' => 'The AI provider timed out. Please retry.'],
            'network'         => ['code' => 'network',         'status' => 502, 'message' => 'Could not reach the AI provider. Check the network and try again.'],
            'empty_reply'     => ['code' => 'empty_reply',     'status' => 502, 'message' => 'The AI provider returned an empty response. Please retry.'],
            default           => ['code' => 'ai_error',        'status' => 500, 'message' => 'Could not generate a reply.'],
        };
    }

    private static function sanitizeAssistantText(string $raw): string
    {
        $cleaned = preg_replace([
            '/^\s*(?:tick|tool_call_id|tool_call|tool_calls)\s*:\s*(?:\{[^\}]*\}|\[[^\]]*\]|[^\r\n]*)\s*$/m',
            '/\b(?:tick|tool_call_id|tool_call|tool_calls)\s*:\s*[\w-]+\b/i',
            '/\n{3,}/',
            '/[ \t]{2,}/',
        ], ['', '', "\n\n", ' '], $raw);

        return trim((string) $cleaned);
    }

    /**
     * Log a thumbs-up / thumbs-down rating for a specific assistant reply.
     * Upserts on (user_id, message_client_id) so a user can flip their vote
     * or add a comment without creating duplicate rows.
     */
    public function feedback(Request $request): JsonResponse
    {
        $data = $request->validate([
            'message_id'      => ['required', 'string', 'max:64'],
            'rating'          => ['required', 'in:up,down'],
            'conversation_id' => ['nullable', 'uuid'],
            'client_id'       => ['nullable', 'string', 'max:64'],
            'locale'          => ['nullable', 'string', 'max:10'],
            'comment'         => ['nullable', 'string', 'max:2000'],
            'question'        => ['nullable', 'string', 'max:4000'],
            'answer'          => ['nullable', 'string', 'max:8000'],
            'tags'            => ['nullable', 'array', 'max:10'],
            'tags.*'          => ['string', 'max:40'],
        ]);

        try {
            $userId = $request->user()?->id;
            // Endpoint is public — fall back to an anon client identifier so the
            // unique index (user_id, message_client_id) stays deterministic per
            // browser/session and upserts work without a NULL user_id.
            $subjectKey = $userId !== null
                ? (string) $userId
                : 'anon:'.($data['client_id'] ?? substr(sha1($request->ip().'|'.$request->userAgent()), 0, 24));

            // The column is varchar(64); a UUID user id + a UUID message id would
            // overflow it and fail the insert, so store a fixed-length digest.
            $clientKey = 'k_'.hash('sha256', $subjectKey.'|'.$data['message_id']);

            AiFeedback::updateOrCreate(
                ['user_id' => $userId, 'message_client_id' => substr($clientKey, 0, 64)],
                [
                    'conversation_id' => $data['conversation_id'] ?? null,
                    'rating'          => $data['rating'],
                    'locale'          => $data['locale'] ?? null,
                    'comment'         => $data['comment'] ?? null,
                    'question'        => $data['question'] ?? null,
                    'answer'          => $data['answer'] ?? null,
                    'tags'            => $data['tags'] ?? null,
                ],
            );

            Log::info('ai.chat.feedback', [
                'user_id'    => $userId,
                'subject'    => $subjectKey,
                'rating'     => $data['rating'],
                'message_id' => $data['message_id'],
                'locale'     => $data['locale'] ?? null,
            ]);

            return ApiResponse::ok(['recorded' => true]);
        } catch (Throwable $e) {
            Log::error('ai.chat.feedback_error', ['message' => $e->getMessage()]);
            return ApiResponse::error('feedback_failed', 'Could not record feedback.', 500);
        }
    }


    public function chat(Request $request): JsonResponse|StreamedResponse
    {
        $data = $request->validate([
            'messages'          => ['required', 'array', 'min:1', 'max:40'],
            'messages.*.role'   => ['required', 'in:user,assistant'],
            'messages.*.content'=> ['required', 'string', 'min:1', 'max:4000'],
            'locale'            => ['nullable', 'string', 'max:10'],
            'conversation_id'   => ['nullable', 'uuid'],
            'stream'            => ['nullable', 'boolean'],
        ]);

        $locale = $data['locale'] ?? 'fr';
        $conversationId = $data['conversation_id'] ?? null;
        $subjectId = $request->user()?->id;

        // Start the single wall-clock budget for this whole turn. Every retry,
        // model fallback, agent round and repair pass draws from it.
        AiDeadline::start();

        // One trace id shared by every AI log line of this turn, so tailing the
        // log (or grepping the id) shows the full story of a single question:
        // plan -> model attempts -> tools -> self-check -> answer.
        AiTrace::start([
            'q'        => AiTrace::preview(end($data['messages'])['content'] ?? ''),
            'locale'   => $locale,
            'stream'   => $request->boolean('stream'),
            'turns'    => count($data['messages']),
            'conv'     => $conversationId,
            'subject'  => $subjectId === null ? null : (string) $subjectId,
            'budget_s' => AiDeadline::remaining(),
        ]);

        if ($request->boolean('stream')) {
            return $this->streamResponse($request, $data['messages'], $locale, $conversationId, $subjectId);
        }



        $startedAt = microtime(true);

        try {
            // Let the model think as long as it needs — no execution cap.
            @set_time_limit(0);
            @ini_set('max_execution_time', '0');
            $result = $this->aiChat()->reply($data['messages'], $locale, $conversationId, $subjectId);

            AiTranscriptLogger::record(
                $request, $data['messages'], $locale, $result['conversation_id'] ?? $conversationId,
                false, 'ok', (string) $result['reply'], null, (int) ((microtime(true) - $startedAt) * 1000),
            );

            return ApiResponse::ok([
                'reply'           => $result['reply'],
                'conversation_id' => $result['conversation_id'],
                'revised'         => $result['revised'] ?? false,
                'violations'      => $result['violations'] ?? [],
                'cached'          => (bool) ($result['cached'] ?? false),
                'degraded'        => (bool) ($result['degraded'] ?? false),
            ]);
        } catch (RuntimeException $e) {
            $info = self::classifyError($e->getMessage());
            AiTrace::warning('ai.chat.failed', ['code' => $info['code'], 'message' => AiTrace::preview($e->getMessage(), 400)]);
            AiTrace::finish(['path' => 'failed', 'code' => $info['code']]);
            AiTranscriptLogger::record(
                $request, $data['messages'], $locale, $conversationId,
                false, 'error', $info['message'], $info['code'], (int) ((microtime(true) - $startedAt) * 1000),
            );
            return ApiResponse::error($info['code'], $info['message'], $info['status']);
        } catch (Throwable $e) {
            AiTrace::error('ai.chat.error', [
                'exception' => $e::class,
                'message'   => AiTrace::preview($e->getMessage(), 400),
                'at'        => basename($e->getFile()).':'.$e->getLine(),
            ]);
            AiTrace::finish(['path' => 'exception', 'code' => 'ai_error']);
            AiTranscriptLogger::record(
                $request, $data['messages'], $locale, $conversationId,
                false, 'error', $e->getMessage(), 'ai_error', (int) ((microtime(true) - $startedAt) * 1000),
            );
            return ApiResponse::error('ai_error', 'Could not generate a reply.', 500);
        }

    }

    /**
     * @param  array<int, array{role: string, content: string}>  $messages
     */
    private function streamResponse(Request $request, array $messages, string $locale, ?string $conversationId, int|string|null $subjectId = null): StreamedResponse
    {
        return response()->stream(function () use ($request, $messages, $locale, $conversationId, $subjectId): void {
            $startedAt = microtime(true);
            // No PHP execution cap: the agent loop may legitimately run for
            // several minutes on complex questions. Heartbeat pings below keep
            // the connection alive; the client aborts when the user stops.
            @set_time_limit(0);
            @ini_set('max_execution_time', '0');
            @ini_set('default_socket_timeout', '0');
            @ini_set('output_buffering', 'off');
            @ini_set('zlib.output_compression', '0');


            while (ob_get_level() > 0) {
                @ob_end_flush();
            }
            ob_implicit_flush(true);

            $emit = static function (array $payload): void {
                echo json_encode($payload, JSON_UNESCAPED_UNICODE)."\n";
                if (ob_get_level() > 0) {
                    ob_flush();
                }
                flush();
            };

            // Flush a first byte immediately: the agent tool loop can run for
            // well over a minute before the first delta, and proxies (nginx
            // proxy_read_timeout, default 60s) drop an idle upstream response,
            // which surfaces in the browser as "Failed to fetch".
            $emit(['type' => 'ping']);

            try {
                $result = $this->aiChat()->replyStream(
                    $messages,
                    $locale,
                    $conversationId,
                    static function (string $delta) use ($emit): void {
                        $emit(['type' => 'delta', 'content' => self::sanitizeAssistantText($delta)]);
                    },
                    $subjectId,
                    static function (array $event) use ($emit): void {
                        if (($event['type'] ?? '') === 'plan') {
                            $emit($event);
                            return;
                        }
                        // Tool start/end events are internal agent metadata and
                        // should not be surfaced in the user-facing stream — but
                        // they still keep the connection alive as heartbeats.
                        $emit(['type' => 'ping']);
                    },
                );

                // Self-check may have rewritten the reply after streaming. If so,
                // tell the client to replace the streamed draft with the corrected text.
                if (! empty($result['revised']) && ($result['reply'] ?? '') !== '') {
                    $emit([
                        'type'       => 'revise',
                        'content'    => self::sanitizeAssistantText((string) $result['reply']),
                        'violations' => $result['violations'] ?? [],
                    ]);
                }

                $finalReply = self::sanitizeAssistantText((string) $result['reply']);

                $emit([
                    'type'            => 'done',
                    'reply'           => $finalReply,
                    'conversation_id' => $result['conversation_id'],
                    'revised'         => (bool) ($result['revised'] ?? false),
                    'cached'          => (bool) ($result['cached'] ?? false),
                    'degraded'        => (bool) ($result['degraded'] ?? false),
                ]);

                AiTranscriptLogger::record(
                    $request, $messages, $locale, $result['conversation_id'] ?? $conversationId,
                    true, 'ok', $finalReply, null, (int) ((microtime(true) - $startedAt) * 1000),
                );
            } catch (RuntimeException $e) {
                $info = self::classifyError($e->getMessage());
                AiTrace::warning('ai.chat.stream_failed', ['code' => $info['code'], 'message' => AiTrace::preview($e->getMessage(), 400)]);
                AiTrace::finish(['path' => 'stream_failed', 'code' => $info['code']]);
                $emit(['type' => 'error', 'code' => $info['code'], 'message' => $info['message']]);
                AiTranscriptLogger::record(
                    $request, $messages, $locale, $conversationId,
                    true, 'error', $info['message'], $info['code'], (int) ((microtime(true) - $startedAt) * 1000),
                );
            } catch (Throwable $e) {
                AiTrace::error('ai.chat.stream_error', [
                    'exception' => $e::class,
                    'message'   => AiTrace::preview($e->getMessage(), 400),
                    'at'        => basename($e->getFile()).':'.$e->getLine(),
                ]);
                AiTrace::finish(['path' => 'stream_exception', 'code' => 'ai_error']);
                $emit(['type' => 'error', 'code' => 'ai_error', 'message' => 'Could not generate a reply.']);
                AiTranscriptLogger::record(
                    $request, $messages, $locale, $conversationId,
                    true, 'error', $e->getMessage(), 'ai_error', (int) ((microtime(true) - $startedAt) * 1000),
                );
            }

        }, 200, [
            'Content-Type'       => 'application/x-ndjson; charset=utf-8',
            'Cache-Control'      => 'no-cache, no-store, no-transform',
            'X-Accel-Buffering'  => 'no',
        ]);
    }
}
