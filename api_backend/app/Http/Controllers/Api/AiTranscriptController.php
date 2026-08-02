<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AiChatTranscript;
use App\Support\Http\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Read-only, unauthenticated feed of every AI exchange, used by the hidden
 * /chat inspection page. Returns questions and answers exactly as recorded,
 * including failed replies.
 */
final class AiTranscriptController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $limit = (int) $request->query('limit', '200');
        $limit = max(1, min($limit, 500));

        $q = AiChatTranscript::query()->orderByDesc('created_at')->limit($limit);

        if (($status = (string) $request->query('status', '')) !== '' && in_array($status, ['ok', 'error'], true)) {
            $q->where('status', $status);
        }
        if (($conv = (string) $request->query('conversation_id', '')) !== '') {
            $q->where('conversation_id', $conv);
        }
        if (($search = trim((string) $request->query('q', ''))) !== '') {
            $q->where(function ($w) use ($search): void {
                $w->where('question', 'like', '%'.$search.'%')
                  ->orWhere('answer', 'like', '%'.$search.'%');
            });
        }

        $rows = $q->get();

        return ApiResponse::ok([
            'items' => $rows->map(fn (AiChatTranscript $r) => [
                'id'              => $r->id,
                'conversation_id' => $r->conversation_id,
                'user_label'      => $r->user_label,
                'locale'          => $r->locale,
                'streamed'        => $r->streamed,
                'status'          => $r->status,
                'error_code'      => $r->error_code,
                'question'        => $r->question,
                'answer'          => $r->answer,
                'duration_ms'     => $r->duration_ms,
                'created_at'      => $r->created_at?->toIso8601String(),
            ])->all(),
            'total' => AiChatTranscript::query()->count(),
        ]);
    }
}
