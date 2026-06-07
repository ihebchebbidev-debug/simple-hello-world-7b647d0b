<?php

/**
 * Price history endpoints — single source of truth for pricing.
 *
 * Stores effective-dated prices for water, fertilizers, pesticides, and labor.
 * Field operations snapshot the resolved price at the time of entry, so closing
 * a price (setting effective_to) does not retroactively change historical data.
 */

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Concerns\PaginatesResources;
use App\Http\Controllers\Concerns\RespondsWithResource;
use App\Http\Controllers\Controller;
use App\Http\Requests\PriceHistory\IndexPriceHistoryRequest;
use App\Http\Requests\PriceHistory\StorePriceHistoryRequest;
use App\Http\Requests\PriceHistory\UpdatePriceHistoryRequest;
use App\Http\Resources\PriceHistoryResource;
use App\Models\PriceHistory;
use App\Support\Http\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class PriceHistoryController extends Controller
{
    use PaginatesResources;
    use RespondsWithResource;

    public function index(IndexPriceHistoryRequest $request): JsonResponse
    {
        $data = $request->validated();
        $query = PriceHistory::query()
            ->orderBy('entity_type')
            ->orderBy('effective_from', 'desc');

        if (! empty($data['entity_type'])) {
            $query->where('entity_type', $data['entity_type']);
        }

        if (! empty($data['entity_id'])) {
            $query->where('entity_id', $data['entity_id']);
        }

        if ($request->boolean('current_only')) {
            $today = now()->toDateString();
            $query->where('effective_from', '<=', $today)
                ->where(function ($q) use ($today): void {
                    $q->whereNull('effective_to')->orWhere('effective_to', '>=', $today);
                });
        } elseif (! empty($data['active_on'])) {
            $on = $data['active_on'];
            $query->where('effective_from', '<=', $on)
                ->where(function ($q) use ($on): void {
                    $q->whereNull('effective_to')->orWhere('effective_to', '>=', $on);
                });
        }

        return $this->paginatedResponse(
            $query->paginate($data['per_page'] ?? 25),
            PriceHistoryResource::class,
        );
    }

    public function store(StorePriceHistoryRequest $request): JsonResponse
    {
        $price = PriceHistory::create([
            ...$request->validated(),
            'created_at' => now(),
            'created_by' => $request->user()?->id,
        ]);

        return $this->resourceResponse(PriceHistoryResource::class, $price, 201);
    }

    public function show(PriceHistory $priceHistory): JsonResponse
    {
        return $this->resourceResponse(PriceHistoryResource::class, $priceHistory);
    }

    public function update(UpdatePriceHistoryRequest $request, PriceHistory $priceHistory): JsonResponse
    {
        $priceHistory->fill($request->validated())->save();

        return $this->resourceResponse(PriceHistoryResource::class, $priceHistory->refresh());
    }

    public function destroy(Request $request, PriceHistory $priceHistory): JsonResponse
    {
        $id = $priceHistory->id;
        $priceHistory->delete();

        return ApiResponse::ok(['id' => $id, 'deleted' => true]);
    }
}
