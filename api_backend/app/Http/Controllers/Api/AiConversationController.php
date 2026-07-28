<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AiConversation;
use App\Support\Http\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Per-user AI chat history. Each row is one conversation with its full message
 * thread stored inline as JSON. Everything is scoped to the authenticated user;
 * cross-user access is impossible because every query starts from `where user_id`.
 */
final class AiConversationController extends Controller
{
    private const MAX_MESSAGES = 80;
    private const MAX_TITLE = 120;

    public function index(Request $request): JsonResponse
    {
        $userId = $request->user()->id;

        $rows = AiConversation::query()
            ->where('user_id', $userId)
            ->orderByDesc('updated_at')
            ->limit(100)
            ->get(['id', 'title', 'updated_at']);

        return ApiResponse::ok([
            'items' => $rows->map(fn ($r) => [
                'id'         => $r->id,
                'title'      => $r->title,
                'updated_at' => $r->updated_at?->toIso8601String(),
            ])->all(),
        ]);
    }

    public function show(Request $request, string $id): JsonResponse
    {
        return ApiResponse::ok($this->present($this->find($request, $id)));
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'title'    => ['nullable', 'string', 'max:'.self::MAX_TITLE],
            'messages' => ['nullable', 'array'],
        ]);

        $conv = AiConversation::create([
            'user_id'  => $request->user()->id,
            'title'    => $this->cleanTitle($data['title'] ?? null),
            'messages' => $this->cleanMessages($data['messages'] ?? []),
        ]);

        return ApiResponse::ok($this->present($conv), 201);
    }

    public function update(Request $request, string $id): JsonResponse
    {
        $data = $request->validate([
            'title'    => ['nullable', 'string', 'max:'.self::MAX_TITLE],
            'messages' => ['nullable', 'array'],
        ]);

        $conv = $this->find($request, $id);

        if (array_key_exists('title', $data) && $data['title'] !== null) {
            $conv->title = $this->cleanTitle($data['title']);
        }
        if (array_key_exists('messages', $data) && is_array($data['messages'])) {
            $conv->messages = $this->cleanMessages($data['messages']);
            if (in_array($conv->title, ['', 'New conversation', 'Nouvelle conversation'], true)) {
                $conv->title = $this->deriveTitle($conv->messages);
            }
        }
        $conv->save();

        return ApiResponse::ok($this->present($conv));
    }

    public function destroy(Request $request, string $id): JsonResponse
    {
        $this->find($request, $id)->delete();
        return ApiResponse::ok(['deleted' => true, 'id' => $id]);
    }

    private function find(Request $request, string $id): AiConversation
    {
        return AiConversation::query()
            ->where('user_id', $request->user()->id)
            ->where('id', $id)
            ->firstOrFail();
    }

    /** @param  array<int, mixed>  $messages */
    private function cleanMessages(array $messages): array
    {
        $out = [];
        foreach ($messages as $m) {
            if (! is_array($m)) continue;
            $role = (string) ($m['role'] ?? '');
            $content = (string) ($m['content'] ?? '');
            if (! in_array($role, ['user', 'assistant'], true)) continue;
            $entry = [
                'id'        => (string) ($m['id'] ?? ''),
                'role'      => $role,
                'content'   => mb_substr($content, 0, 8000),
                'createdAt' => is_numeric($m['createdAt'] ?? null) ? (int) $m['createdAt'] : (int) (microtime(true) * 1000),
            ];
            if (($m['status'] ?? null) === 'error') $entry['status'] = 'error';
            if (isset($m['rating']) && in_array($m['rating'], ['up', 'down'], true)) {
                $entry['rating'] = $m['rating'];
            }
            $out[] = $entry;
        }
        return array_slice($out, -self::MAX_MESSAGES);
    }

    private function cleanTitle(?string $title): string
    {
        $title = trim((string) $title);
        if ($title === '') return 'New conversation';
        return mb_substr($title, 0, self::MAX_TITLE);
    }

    /** @param array<int, array<string, mixed>> $messages */
    private function deriveTitle(array $messages): string
    {
        foreach ($messages as $m) {
            if (($m['role'] ?? '') === 'user' && ! empty($m['content'])) {
                return $this->cleanTitle(mb_substr((string) $m['content'], 0, 60));
            }
        }
        return 'New conversation';
    }

    private function present(AiConversation $conv): array
    {
        return [
            'id'         => $conv->id,
            'title'      => $conv->title,
            'messages'   => is_array($conv->messages) ? $conv->messages : [],
            'updated_at' => $conv->updated_at?->toIso8601String(),
            'created_at' => $conv->created_at?->toIso8601String(),
        ];
    }
}