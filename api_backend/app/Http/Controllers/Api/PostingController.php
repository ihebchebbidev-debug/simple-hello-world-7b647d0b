<?php

/**
 * Offline sync queue — idempotent operation submission.
 *
 * Mobile clients assign a UUID client_id before going offline. When connectivity
 * is restored the Service Worker replays the queued request to POST /postings.
 * The server uses client_id as an idempotency key so replayed requests are safe.
 */

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Concerns\PaginatesResources;
use App\Http\Controllers\Controller;
use App\Http\Requests\Posting\BulkStorePostingRequest;
use App\Http\Requests\Posting\StorePostingRequest;
use App\Http\Resources\PostingResource;
use App\Models\Fertilizer;
use App\Models\FertilizationOperation;
use App\Models\HarvestOperation;
use App\Models\IrrigationOperation;
use App\Models\Pesticide;
use App\Models\PhytosanitaryOperation;
use App\Models\Posting;
use App\Support\Http\ApiResponse;
use App\Support\OperationPriceResolver;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

final class PostingController extends Controller
{
    use PaginatesResources;

    public function __construct(private readonly OperationPriceResolver $prices) {}

    public function index(Request $request): JsonResponse
    {
        $perPage = (int) ($request->query('per_page', 25));
        $query   = Posting::query()->orderByDesc('submitted_at');

        if ($request->filled('operation_type')) {
            $query->where('operation_type', $request->query('operation_type'));
        }
        if ($request->filled('status')) {
            $query->where('status', $request->query('status'));
        }

        return $this->paginatedResponse($query->paginate(min($perPage, 100)), PostingResource::class);
    }

    public function show(Posting $posting): JsonResponse
    {
        return ApiResponse::ok(PostingResource::make($posting)->resolve());
    }

    public function store(StorePostingRequest $request): JsonResponse
    {
        $data = $request->validated();

        $existing = Posting::where('client_id', $data['client_id'])->first();
        if ($existing) {
            return ApiResponse::ok(PostingResource::make($existing)->resolve());
        }

        $posting     = null;
        $userId      = $request->user()?->id;
        $submittedAt = $data['submitted_at'] ?? now();

        // Clear any leftover aborted transaction state on a pooled
        // connection so the first real query doesn't get 25P02.
        try { DB::statement('ROLLBACK'); } catch (\Throwable) {}

        try {
            // No DB::transaction wrapper: the operation insert + posting
            // insert are independent. Wrapping them poisons the whole txn
            // on Postgres if any subquery (e.g. price lookup) fails, and
            // then the "failed posting" insert in the catch block ALSO
            // hits 25P02, hiding the real error.
            $this->dispatchOperation($data['operation_type'], $data['payload'], $userId);

            $posting = Posting::create([
                'client_id'      => $data['client_id'],
                'operation_type' => $data['operation_type'],
                'payload'        => $data['payload'],
                'status'         => 'synced',
                'device_info'    => $data['device_info'] ?? null,
                'submitted_at'   => $submittedAt,
                'synced_at'      => now(),
                'created_by'     => $userId,
            ]);
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::error('posting.dispatch_failed', [
                'client_id'      => $data['client_id'],
                'operation_type' => $data['operation_type'],
                'exception'      => $e::class,
                'message'        => $e->getMessage(),
            ]);

            // Force-clear any aborted txn so the failed-posting insert works.
            try { DB::statement('ROLLBACK'); } catch (\Throwable) {}

            $posting = Posting::create([
                'client_id'      => $data['client_id'],
                'operation_type' => $data['operation_type'],
                'payload'        => $data['payload'],
                'status'         => 'failed',
                'error_message'  => $e->getMessage(),
                'device_info'    => $data['device_info'] ?? null,
                'submitted_at'   => $submittedAt,
                'created_by'     => $userId,
            ]);
        }

        $isSynced = ($posting->status instanceof \BackedEnum ? $posting->status->value : $posting->status) === 'synced';

        return ApiResponse::ok(PostingResource::make($posting)->resolve(), $isSynced ? 201 : 422);
    }

    /**
     * Bulk submission: same idempotency rules as the single-store endpoint.
     * Each item is processed independently — partial success is allowed and
     * the response lists the per-client_id outcome.
     */
    public function bulkStore(BulkStorePostingRequest $request): JsonResponse
    {
        $items   = $request->validated()['postings'];
        $results = [];
        $synced  = 0;
        $failed  = 0;

        foreach ($items as $item) {
            $existing = Posting::where('client_id', $item['client_id'])->first();
            if ($existing) {
                $results[] = PostingResource::make($existing)->resolve();
                ($existing->status instanceof \BackedEnum ? $existing->status->value : $existing->status) === 'synced'
                    ? $synced++ : $failed++;
                continue;
            }

            $posting     = null;
            $userId      = $request->user()?->id;
            $submittedAt = $item['submitted_at'] ?? now();

            try { DB::statement('ROLLBACK'); } catch (\Throwable) {}

            try {
                $this->dispatchOperation($item['operation_type'], $item['payload'], $userId);
                $posting = Posting::create([
                    'client_id'      => $item['client_id'],
                    'operation_type' => $item['operation_type'],
                    'payload'        => $item['payload'],
                    'status'         => 'synced',
                    'device_info'    => $item['device_info'] ?? null,
                    'submitted_at'   => $submittedAt,
                    'synced_at'      => now(),
                    'created_by'     => $userId,
                ]);
            } catch (\Throwable $e) {
                \Illuminate\Support\Facades\Log::error('posting.bulk_dispatch_failed', [
                    'client_id'      => $item['client_id'],
                    'operation_type' => $item['operation_type'],
                    'exception'      => $e::class,
                    'message'        => $e->getMessage(),
                ]);

                try { DB::statement('ROLLBACK'); } catch (\Throwable) {}

                $posting = Posting::create([
                    'client_id'      => $item['client_id'],
                    'operation_type' => $item['operation_type'],
                    'payload'        => $item['payload'],
                    'status'         => 'failed',
                    'error_message'  => $e->getMessage(),
                    'device_info'    => $item['device_info'] ?? null,
                    'submitted_at'   => $submittedAt,
                    'created_by'     => $userId,
                ]);
            }

            $results[] = PostingResource::make($posting)->resolve();
            ($posting->status instanceof \BackedEnum ? $posting->status->value : $posting->status) === 'synced'
                ? $synced++ : $failed++;
        }

        return ApiResponse::ok([
            'postings' => $results,
            'summary'  => [
                'total'  => count($items),
                'synced' => $synced,
                'failed' => $failed,
            ],
        ]);
    }

    public function retry(Request $request, Posting $posting): JsonResponse
    {
        $currentStatus = $posting->status instanceof \BackedEnum
            ? $posting->status->value
            : $posting->status;

        if ($currentStatus === 'synced') {
            return ApiResponse::error('already_synced', 'This posting has already been synced.', 422);
        }

        try { DB::statement('ROLLBACK'); } catch (\Throwable) {}

        $userId = $request->user()?->id;

        try {
            $this->dispatchOperation(
                $posting->operation_type instanceof \BackedEnum
                    ? $posting->operation_type->value
                    : $posting->operation_type,
                (array) $posting->payload,
                $userId,
            );

            $posting->forceFill([
                'status'        => 'synced',
                'error_message' => null,
                'synced_at'     => now(),
                'retry_count'   => $posting->retry_count + 1,
            ])->save();
        } catch (\Throwable $e) {
            try { DB::statement('ROLLBACK'); } catch (\Throwable) {}
            $posting->forceFill([
                'status'        => 'failed',
                'error_message' => $e->getMessage(),
                'retry_count'   => $posting->retry_count + 1,
            ])->save();
        }

        return ApiResponse::ok(PostingResource::make($posting->refresh())->resolve());
    }

    /** @param array<string, mixed> $payload */
    private function dispatchOperation(string $type, array $payload, ?string $userId): void
    {
        $date       = $payload['operation_date'] ?? now()->toDateString();
        $campaignId = $payload['campaign_id'] ?? null;

        // CRITICAL: resolve prices/units BEFORE any write so price_history /
        // water_config / labor_config lookups can't run inside a transaction
        // that a prior failure might have aborted (Postgres 25P02). Also
        // guarantees the same date snapshot regardless of write ordering.
        if ($type === 'irrigation') {
            $unit  = $this->prices->activeWaterUnit();
            $price = $this->prices->priceFor('water', null, $date);

            IrrigationOperation::create([
                'plot_id'        => $payload['plot_id'],
                'campaign_id'    => $campaignId,
                'operation_date' => $date,
                'water_quantity' => $payload['water_quantity'],
                'unit_at_entry'  => $unit,
                'price_at_entry' => $price,
                'created_by'     => $userId,
                'updated_by'     => $userId,
            ]);

            return;
        }

        if ($type === 'fertilization') {
            // Accept both flat payload (single item) and mobile `items: [...]` shape.
            $items = isset($payload['items']) && is_array($payload['items'])
                ? $payload['items']
                : [['fertilizer_id' => $payload['fertilizer_id'] ?? null, 'quantity_applied' => $payload['quantity_applied'] ?? null]];

            foreach ($items as $item) {
                if (empty($item['fertilizer_id']) || !isset($item['quantity_applied'])) {
                    throw new \InvalidArgumentException('Each fertilization item requires fertilizer_id and quantity_applied.');
                }
                $fertilizer = Fertilizer::findOrFail($item['fertilizer_id']);
                $price      = $this->prices->priceFor('fertilizer', $fertilizer->id, $date);

                FertilizationOperation::create([
                    'plot_id'          => $payload['plot_id'],
                    'campaign_id'      => $campaignId,
                    'fertilizer_id'    => $fertilizer->id,
                    'operation_date'   => $date,
                    'quantity_applied' => $item['quantity_applied'],
                    'n_at_entry'       => $fertilizer->n_percent,
                    'p_at_entry'       => $fertilizer->p_percent,
                    'k_at_entry'       => $fertilizer->k_percent,
                    'price_at_entry'   => $price,
                    'created_by'       => $userId,
                    'updated_by'       => $userId,
                ]);
            }

            return;
        }

        if ($type === 'phytosanitary') {
            $items = isset($payload['items']) && is_array($payload['items'])
                ? $payload['items']
                : [['pesticide_id' => $payload['pesticide_id'] ?? null, 'quantity_applied' => $payload['quantity_applied'] ?? null]];

            foreach ($items as $item) {
                if (empty($item['pesticide_id']) || !isset($item['quantity_applied'])) {
                    throw new \InvalidArgumentException('Each phytosanitary item requires pesticide_id and quantity_applied.');
                }
                $pesticide = Pesticide::findOrFail($item['pesticide_id']);
                $price     = $this->prices->priceFor('pesticide', $pesticide->id, $date);

                PhytosanitaryOperation::create([
                    'plot_id'          => $payload['plot_id'],
                    'campaign_id'      => $campaignId,
                    'pesticide_id'     => $pesticide->id,
                    'operation_date'   => $date,
                    'quantity_applied' => $item['quantity_applied'],
                    // Spray-mix volume is recorded once per treatment but stored
                    // on every pesticide row so the report can derive volume/ha.
                    'water_volume_l'   => $item['water_volume_l'] ?? $payload['water_volume_l'] ?? null,
                    // v5: each pesticide carries its own targeted bioagresseur;
                    // fall back to a treatment-wide value for older payloads.
                    'target_pest'      => $item['target_pest'] ?? $payload['target_pest'] ?? null,
                    'remarks'          => $payload['remarks'] ?? null,
                    'price_at_entry'   => $price,
                    'created_by'       => $userId,
                    'updated_by'       => $userId,
                ]);
            }

            return;
        }

        if ($type === 'harvest') {
            $rate = $this->prices->priceFor('labor', null, $date);

            HarvestOperation::create([
                'plot_id'             => $payload['plot_id'],
                'campaign_id'         => $campaignId,
                'operation_date'      => $date,
                'num_workers'         => $payload['num_workers'],
                'days_worked'         => $payload['days_worked'],
                'quantity_harvested'  => $payload['quantity_harvested'],
                'daily_rate_at_entry' => $rate,
                'created_by'          => $userId,
                'updated_by'          => $userId,
            ]);

            return;
        }

        throw new \InvalidArgumentException("Unknown operation type: {$type}");
    }
}
