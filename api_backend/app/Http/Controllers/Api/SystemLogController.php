<?php

/**
 * System log endpoints (admin-only audit trail).
 *
 * GET endpoints let admins paginate, filter, and inspect the audit trail.
 * POST is reserved for the application itself (e.g. middleware, jobs)
 * recording auditable events; only admins may invoke it directly.
 */

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Concerns\PaginatesResources;
use App\Http\Controllers\Concerns\RespondsWithResource;
use App\Http\Controllers\Controller;
use App\Http\Requests\SystemLog\IndexSystemLogRequest;
use App\Http\Requests\SystemLog\StoreSystemLogRequest;
use App\Http\Resources\SystemLogResource;
use App\Models\SystemLog;
use App\Support\Http\ApiResponse;
use Illuminate\Http\JsonResponse;

final class SystemLogController extends Controller
{
    use PaginatesResources;
    use RespondsWithResource;

    public function index(IndexSystemLogRequest $request): JsonResponse
    {
        $data = $request->validated();
        $query = SystemLog::query()->orderByDesc('created_at')->orderByDesc('id');

        foreach (['level', 'category', 'action', 'entity_type', 'entity_id', 'user_id'] as $field) {
            if (! empty($data[$field])) {
                $query->where($field, $data[$field]);
            }
        }

        if (! empty($data['date_from'])) {
            $query->whereDate('created_at', '>=', $data['date_from']);
        }

        if (! empty($data['date_to'])) {
            $query->whereDate('created_at', '<=', $data['date_to']);
        }

        if (! empty($data['search'])) {
            $search = strtolower($data['search']);
            $query->where(function ($q) use ($search): void {
                $q->whereRaw('LOWER(action) LIKE ?', ["%{$search}%"])
                  ->orWhereRaw('LOWER(category) LIKE ?', ["%{$search}%"])
                  ->orWhereRaw('LOWER(COALESCE(entity_type, \'\')) LIKE ?', ["%{$search}%"]);
            });
        }

        return $this->paginatedResponse(
            $query->paginate($data['per_page'] ?? 50),
            SystemLogResource::class,
        );
    }

    public function show(SystemLog $systemLog): JsonResponse
    {
        return $this->resourceResponse(SystemLogResource::class, $systemLog);
    }

    public function store(StoreSystemLogRequest $request): JsonResponse
    {
        $data = $request->validated();
        $user = $request->user();

        $log = SystemLog::record(
            category:   $data['category'],
            action:     $data['action'],
            details:    $data['details']    ?? null,
            entityType: $data['entity_type'] ?? null,
            entityId:   $data['entity_id']   ?? null,
            level:      $data['level']       ?? 'info',
            userId:     $user?->id,
            ipAddress:  $request->ip(),
            userAgent:  substr((string) $request->userAgent(), 0, 500),
        );

        return $this->resourceResponse(SystemLogResource::class, $log, 201);
    }

    /**
     * Aggregated counts for an admin observability dashboard widget.
     */
    public function stats(): JsonResponse
    {
        $byLevel = SystemLog::query()
            ->selectRaw('level, COUNT(*) as total')
            ->groupBy('level')
            ->pluck('total', 'level')
            ->all();

        $byCategory = SystemLog::query()
            ->selectRaw('category, COUNT(*) as total')
            ->groupBy('category')
            ->pluck('total', 'category')
            ->all();

        return ApiResponse::ok([
            'total'       => SystemLog::query()->count(),
            'last_24h'    => SystemLog::query()->where('created_at', '>=', now()->subDay())->count(),
            'by_level'    => $byLevel,
            'by_category' => $byCategory,
        ]);
    }
}
