<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Concerns\PaginatesResources;
use App\Http\Controllers\Concerns\RespondsWithResource;
use App\Http\Controllers\Controller;
use App\Http\Requests\FertilizationOperation\IndexFertilizationOperationRequest;
use App\Http\Requests\FertilizationOperation\StoreFertilizationOperationRequest;
use App\Http\Requests\FertilizationOperation\UpdateFertilizationOperationRequest;
use App\Http\Resources\FertilizationOperationResource;
use App\Models\Fertilizer;
use App\Models\FertilizationOperation;
use App\Support\Http\ApiResponse;
use App\Support\OperationPriceResolver;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class FertilizationOperationController extends Controller
{
    use PaginatesResources;
    use RespondsWithResource;

    public function __construct(private readonly OperationPriceResolver $prices) {}

    public function index(IndexFertilizationOperationRequest $request): JsonResponse
    {
        $data = $request->validated();
        $query = FertilizationOperation::with(['plot', 'fertilizer'])
            ->orderBy('operation_date', 'desc');

        if (! empty($data['plot_id'])) {
            $query->where('plot_id', $data['plot_id']);
        }
        if (! empty($data['fertilizer_id'])) {
            $query->where('fertilizer_id', $data['fertilizer_id']);
        }
        if (! empty($data['date_from'])) {
            $query->where('operation_date', '>=', $data['date_from']);
        }
        if (! empty($data['date_to'])) {
            $query->where('operation_date', '<=', $data['date_to']);
        }
        if (! empty($data['nutrient'])) {
            // Only ops whose fertilizer carries the requested nutrient.
            // Uses the snapshot *_at_entry columns so historical rows whose
            // fertilizer composition changed later still match correctly.
            $col = match ($data['nutrient']) {
                'n' => 'n_at_entry',
                'p' => 'p_at_entry',
                'k' => 'k_at_entry',
            };
            $query->where($col, '>', 0);
        }



        return $this->paginatedResponse(
            $query->paginate($data['per_page'] ?? 25),
            FertilizationOperationResource::class,
        );
    }

    public function store(StoreFertilizationOperationRequest $request): JsonResponse
    {
        $data     = $request->validated();
        $date     = $data['operation_date'];
        $fertilizer = Fertilizer::findOrFail($data['fertilizer_id']);

        $operation = FertilizationOperation::create([
            'plot_id'          => $data['plot_id'],
            'campaign_id'      => $data['campaign_id'] ?? null,
            'fertilizer_id'    => $fertilizer->id,
            'operation_date'   => $date,
            'quantity_applied' => $data['quantity_applied'],
            'n_at_entry'       => $fertilizer->n_percent,
            'p_at_entry'       => $fertilizer->p_percent,
            'k_at_entry'       => $fertilizer->k_percent,
            'price_at_entry'   => $this->prices->priceFor('fertilizer', $fertilizer->id, $date),
            'posting_id'       => $data['posting_id'] ?? null,
            'created_by'       => $request->user()?->id,
            'updated_by'       => $request->user()?->id,
        ]);

        return $this->resourceResponse(
            FertilizationOperationResource::class,
            $operation->load(['plot', 'fertilizer']),
            201,
        );
    }

    public function show(FertilizationOperation $fertilizationOperation): JsonResponse
    {
        return $this->resourceResponse(
            FertilizationOperationResource::class,
            $fertilizationOperation->load(['plot', 'fertilizer']),
        );
    }

    public function update(UpdateFertilizationOperationRequest $request, FertilizationOperation $fertilizationOperation): JsonResponse
    {
        $data = $request->validated();

        if (isset($data['fertilizer_id']) && $data['fertilizer_id'] !== $fertilizationOperation->fertilizer_id) {
            $fertilizer = Fertilizer::findOrFail($data['fertilizer_id']);
            $data['n_at_entry']     = $fertilizer->n_percent;
            $data['p_at_entry']     = $fertilizer->p_percent;
            $data['k_at_entry']     = $fertilizer->k_percent;
            $data['price_at_entry'] = $this->prices->priceFor(
                'fertilizer',
                $fertilizer->id,
                $data['operation_date'] ?? $fertilizationOperation->operation_date?->toDateString(),
            );
        }

        $fertilizationOperation->fill([...$data, 'updated_by' => $request->user()?->id])->save();

        return $this->resourceResponse(
            FertilizationOperationResource::class,
            $fertilizationOperation->refresh()->load(['plot', 'fertilizer']),
        );
    }

    public function destroy(Request $request, FertilizationOperation $fertilizationOperation): JsonResponse
    {
        $id = $fertilizationOperation->id;
        $fertilizationOperation->delete();

        return ApiResponse::ok(['id' => $id, 'deleted' => true]);
    }
}
