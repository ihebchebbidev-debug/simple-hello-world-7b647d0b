<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Concerns\PaginatesResources;
use App\Http\Controllers\Concerns\RespondsWithResource;
use App\Http\Controllers\Controller;
use App\Http\Requests\HarvestOperation\IndexHarvestOperationRequest;
use App\Http\Requests\HarvestOperation\StoreHarvestOperationRequest;
use App\Http\Requests\HarvestOperation\UpdateHarvestOperationRequest;
use App\Http\Resources\HarvestOperationResource;
use App\Models\HarvestOperation;
use App\Support\Http\ApiResponse;
use App\Support\OperationPriceResolver;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class HarvestOperationController extends Controller
{
    use PaginatesResources;
    use RespondsWithResource;

    public function __construct(private readonly OperationPriceResolver $prices) {}

    public function index(IndexHarvestOperationRequest $request): JsonResponse
    {
        $data = $request->validated();
        $query = HarvestOperation::with('plot')
            ->orderBy('operation_date', 'desc');

        if (! empty($data['plot_id'])) {
            $query->where('plot_id', $data['plot_id']);
        }
        if (! empty($data['date_from'])) {
            $query->where('operation_date', '>=', $data['date_from']);
        }
        if (! empty($data['date_to'])) {
            $query->where('operation_date', '<=', $data['date_to']);
        }

        return $this->paginatedResponse(
            $query->paginate($data['per_page'] ?? 25),
            HarvestOperationResource::class,
        );
    }

    public function store(StoreHarvestOperationRequest $request): JsonResponse
    {
        $data = $request->validated();
        $date = $data['operation_date'];

        $operation = HarvestOperation::create([
            'plot_id'             => $data['plot_id'],
            'campaign_id'         => $data['campaign_id'] ?? null,
            'operation_date'      => $date,
            'num_workers'         => $data['num_workers'],
            'days_worked'         => $data['days_worked'],
            'quantity_harvested'  => $data['quantity_harvested'],
            'daily_rate_at_entry' => $this->prices->priceFor('labor', null, $date),
            'posting_id'          => $data['posting_id'] ?? null,
            'created_by'          => $request->user()?->id,
            'updated_by'          => $request->user()?->id,
        ]);

        return $this->resourceResponse(HarvestOperationResource::class, $operation->load('plot'), 201);
    }

    public function show(HarvestOperation $harvestOperation): JsonResponse
    {
        return $this->resourceResponse(
            HarvestOperationResource::class,
            $harvestOperation->load('plot'),
        );
    }

    public function update(UpdateHarvestOperationRequest $request, HarvestOperation $harvestOperation): JsonResponse
    {
        $harvestOperation->fill([
            ...$request->validated(),
            'updated_by' => $request->user()?->id,
        ])->save();

        return $this->resourceResponse(
            HarvestOperationResource::class,
            $harvestOperation->refresh()->load('plot'),
        );
    }

    public function destroy(Request $request, HarvestOperation $harvestOperation): JsonResponse
    {
        $id = $harvestOperation->id;
        $harvestOperation->delete();

        return ApiResponse::ok(['id' => $id, 'deleted' => true]);
    }
}
