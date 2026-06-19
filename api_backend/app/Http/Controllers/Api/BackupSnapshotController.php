<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Concerns\RespondsWithResource;
use App\Http\Requests\BackupSnapshot\StoreBackupSnapshotRequest;
use App\Http\Resources\BackupSnapshotResource;
use App\Models\BackupSnapshot;
use App\Services\BackupService;
use App\Support\Http\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class BackupSnapshotController extends Controller
{
    use RespondsWithResource;

    public function __construct(private readonly BackupService $service) {}

    /** List all snapshots (metadata only — snapshot_data excluded for performance). */
    public function index(): JsonResponse
    {
        $snapshots = BackupSnapshot::select([
                'id', 'label', 'created_by', 'size_bytes',
                'status', 'metadata', 'notes', 'created_at', 'updated_at',
            ])
            ->with('creator:id,name')
            ->orderByDesc('created_at')
            ->get();

        return ApiResponse::ok(BackupSnapshotResource::collection($snapshots)->resolve());
    }

    /** Create a new snapshot of the current database state. */
    public function store(StoreBackupSnapshotRequest $request): JsonResponse
    {
        $snapshot = $this->service->createSnapshot(
            $request->user(),
            $request->validated('label'),
        );

        // Re-load creator relationship for the response
        $snapshot->load('creator:id,name');

        return $this->resourceResponse(BackupSnapshotResource::class, $snapshot, 201);
    }

    /**
     * Restore the entire database to the state captured in this snapshot.
     * The authenticated user is preserved so their session stays valid.
     */
    public function restore(Request $request, string $id): JsonResponse
    {
        $snapshot = BackupSnapshot::findOrFail($id);

        if ($snapshot->status === 'restoring') {
            return ApiResponse::error(
                'already_restoring',
                'A restore is already in progress for this snapshot.',
                409,
            );
        }

        $this->service->restoreFromSnapshot($snapshot, $request->user());

        return ApiResponse::ok(['restored' => true, 'snapshot_id' => $snapshot->id]);
    }

    /** Delete a snapshot permanently. Cannot delete while a restore is running. */
    public function destroy(string $id): JsonResponse
    {
        $snapshot = BackupSnapshot::findOrFail($id);

        if ($snapshot->status === 'restoring') {
            return ApiResponse::error(
                'restoring',
                'Cannot delete a snapshot while a restore is in progress.',
                409,
            );
        }

        $snapshot->delete();

        return response()->json(null, 204);
    }
}
