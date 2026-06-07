<?php

/**
 * In-app notifications endpoints scoped to the current user.
 *
 * The mobile + admin shells poll these to show the bell badge and the
 * Notifications page. Mark-as-read is idempotent.
 */

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Concerns\PaginatesResources;
use App\Http\Controllers\Controller;
use App\Http\Requests\Notification\IndexNotificationRequest;
use App\Http\Resources\NotificationResource;
use App\Models\Notification;
use App\Support\Http\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class NotificationController extends Controller
{
    use PaginatesResources;

    public function index(IndexNotificationRequest $request): JsonResponse
    {
        $data    = $request->validated();
        $userId  = $request->user()?->id;
        $perPage = (int) ($data['per_page'] ?? 25);

        $query = Notification::query()
            ->where('user_id', $userId)
            ->orderByDesc('created_at');

        if (! empty($data['unread_only'])) {
            $query->whereNull('read_at');
        }

        if (! empty($data['type'])) {
            $query->where('type', $data['type']);
        }

        return $this->paginatedResponse($query->paginate($perPage), NotificationResource::class);
    }

    public function unreadCount(Request $request): JsonResponse
    {
        $count = Notification::query()
            ->where('user_id', $request->user()?->id)
            ->whereNull('read_at')
            ->count();

        return ApiResponse::ok(['unread_count' => $count]);
    }

    public function markAsRead(Request $request, Notification $notification): JsonResponse
    {
        $this->ensureOwnership($request, $notification);

        if ($notification->read_at === null) {
            $notification->forceFill(['read_at' => now()])->save();
        }

        return ApiResponse::ok(NotificationResource::make($notification->refresh())->resolve());
    }

    public function markAllAsRead(Request $request): JsonResponse
    {
        $affected = Notification::query()
            ->where('user_id', $request->user()?->id)
            ->whereNull('read_at')
            ->update(['read_at' => now()]);

        return ApiResponse::ok(['marked' => $affected]);
    }

    public function destroy(Request $request, Notification $notification): JsonResponse
    {
        $this->ensureOwnership($request, $notification);
        $notification->delete();

        return ApiResponse::ok(['deleted' => true]);
    }

    private function ensureOwnership(Request $request, Notification $notification): void
    {
        if ($notification->user_id !== $request->user()?->id) {
            abort(404);
        }
    }
}
