<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Concerns\PaginatesResources;
use App\Http\Controllers\Concerns\RespondsWithResource;
use App\Http\Controllers\Controller;
use App\Http\Requests\PhytosanitaryOperation\IndexPhytosanitaryOperationRequest;
use App\Http\Requests\PhytosanitaryOperation\StorePhytosanitaryOperationRequest;
use App\Http\Requests\PhytosanitaryOperation\UpdatePhytosanitaryOperationRequest;
use App\Http\Resources\PhytosanitaryOperationResource;
use App\Models\Pesticide;
use App\Models\PhytosanitaryOperation;
use App\Support\Http\ApiResponse;
use App\Support\OperationPriceResolver;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class PhytosanitaryOperationController extends Controller
{
    use PaginatesResources;
    use RespondsWithResource;

    public function __construct(private readonly OperationPriceResolver $prices) {}

    public function index(IndexPhytosanitaryOperationRequest $request): JsonResponse
    {
        $data = $request->validated();
        $query = PhytosanitaryOperation::with(['plot', 'pesticide'])
            ->orderBy('operation_date', 'desc');

        if (! empty($data['plot_id'])) {
            $query->where('plot_id', $data['plot_id']);
        }
        if (! empty($data['pesticide_id'])) {
            $query->where('pesticide_id', $data['pesticide_id']);
        }
        if (! empty($data['date_from'])) {
            $query->where('operation_date', '>=', $data['date_from']);
        }
        if (! empty($data['date_to'])) {
            $query->where('operation_date', '<=', $data['date_to']);
        }

        return $this->paginatedResponse(
            $query->paginate($data['per_page'] ?? 25),
            PhytosanitaryOperationResource::class,
        );
    }

    public function store(StorePhytosanitaryOperationRequest $request): JsonResponse
    {
        $data      = $request->validated();
        $date      = $data['operation_date'];
        $pesticide = Pesticide::findOrFail($data['pesticide_id']);

        $operation = PhytosanitaryOperation::create([
            'plot_id'          => $data['plot_id'],
            'campaign_id'      => $data['campaign_id'] ?? null,
            'pesticide_id'     => $pesticide->id,
            'operation_date'   => $date,
            'quantity_applied' => $data['quantity_applied'],
            'water_volume_l'   => $data['water_volume_l'] ?? null,
            'target_pest'      => $data['target_pest'] ?? null,
            'remarks'          => $data['remarks'] ?? null,
            'price_at_entry'   => $this->prices->priceFor('pesticide', $pesticide->id, $date),
            'posting_id'       => $data['posting_id'] ?? null,
            'created_by'       => $request->user()?->id,
            'updated_by'       => $request->user()?->id,
        ]);

        return $this->resourceResponse(
            PhytosanitaryOperationResource::class,
            $operation->load(['plot', 'pesticide']),
            201,
        );
    }

    public function show(PhytosanitaryOperation $phytosanitaryOperation): JsonResponse
    {
        return $this->resourceResponse(
            PhytosanitaryOperationResource::class,
            $phytosanitaryOperation->load(['plot', 'pesticide']),
        );
    }

    public function update(UpdatePhytosanitaryOperationRequest $request, PhytosanitaryOperation $phytosanitaryOperation): JsonResponse
    {
        $data = $request->validated();

        if (isset($data['pesticide_id']) && $data['pesticide_id'] !== $phytosanitaryOperation->pesticide_id) {
            $pesticide = Pesticide::findOrFail($data['pesticide_id']);
            $data['price_at_entry'] = $this->prices->priceFor(
                'pesticide',
                $pesticide->id,
                $data['operation_date'] ?? $phytosanitaryOperation->operation_date?->toDateString(),
            );
        }

        $phytosanitaryOperation->fill([...$data, 'updated_by' => $request->user()?->id])->save();

        return $this->resourceResponse(
            PhytosanitaryOperationResource::class,
            $phytosanitaryOperation->refresh()->load(['plot', 'pesticide']),
        );
    }

    public function destroy(Request $request, PhytosanitaryOperation $phytosanitaryOperation): JsonResponse
    {
        $id = $phytosanitaryOperation->id;
        $phytosanitaryOperation->delete();

        return ApiResponse::ok(['id' => $id, 'deleted' => true]);
    }
}
