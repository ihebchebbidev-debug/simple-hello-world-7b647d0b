<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Concerns\PaginatesResources;
use App\Http\Controllers\Concerns\RespondsWithResource;
use App\Http\Controllers\Controller;
use App\Http\Requests\IrrigationOperation\IndexIrrigationOperationRequest;
use App\Http\Requests\IrrigationOperation\StoreIrrigationOperationRequest;
use App\Http\Requests\IrrigationOperation\UpdateIrrigationOperationRequest;
use App\Http\Resources\IrrigationOperationResource;
use App\Models\IrrigationOperation;
use App\Support\Http\ApiResponse;
use App\Support\OperationPriceResolver;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class IrrigationOperationController extends Controller
{
    use PaginatesResources;
    use RespondsWithResource;

    public function __construct(private readonly OperationPriceResolver $prices) {}

    public function index(IndexIrrigationOperationRequest $request): JsonResponse
    {
        $data = $request->validated();
        $query = IrrigationOperation::with('plot')
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
            IrrigationOperationResource::class,
        );
    }

    public function store(StoreIrrigationOperationRequest $request): JsonResponse
    {
        $data = $request->validated();
        $date = $data['operation_date'];

        $operation = IrrigationOperation::create([
            'plot_id'        => $data['plot_id'],
            'campaign_id'    => $data['campaign_id'] ?? null,
            'operation_date' => $date,
            'water_quantity' => $data['water_quantity'],
            'unit_at_entry'  => $this->prices->activeWaterUnit(),
            'price_at_entry' => $this->prices->priceFor('water', null, $date),
            'posting_id'     => $data['posting_id'] ?? null,
            'created_by'     => $request->user()?->id,
            'updated_by'     => $request->user()?->id,
        ]);

        return $this->resourceResponse(IrrigationOperationResource::class, $operation->load('plot'), 201);
    }

    public function show(IrrigationOperation $irrigationOperation): JsonResponse
    {
        return $this->resourceResponse(
            IrrigationOperationResource::class,
            $irrigationOperation->load('plot'),
        );
    }

    public function update(UpdateIrrigationOperationRequest $request, IrrigationOperation $irrigationOperation): JsonResponse
    {
        $irrigationOperation->fill([
            ...$request->validated(),
            'updated_by' => $request->user()?->id,
        ])->save();

        return $this->resourceResponse(
            IrrigationOperationResource::class,
            $irrigationOperation->refresh()->load('plot'),
        );
    }

    public function destroy(Request $request, IrrigationOperation $irrigationOperation): JsonResponse
    {
        $id = $irrigationOperation->id;
        $irrigationOperation->delete();

        return ApiResponse::ok(['id' => $id, 'deleted' => true]);
    }
}
