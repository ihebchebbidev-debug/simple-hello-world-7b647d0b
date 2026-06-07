<?php

/**
 * Water configuration endpoints.
 *
 * Stores the global water measurement unit configuration. Pricing for water
 * lives in the price_history table with entity_type=water.
 */

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Concerns\PaginatesResources;
use App\Http\Controllers\Concerns\RespondsWithResource;
use App\Http\Controllers\Controller;
use App\Http\Requests\WaterConfig\IndexWaterConfigRequest;
use App\Http\Requests\WaterConfig\StoreWaterConfigRequest;
use App\Http\Requests\WaterConfig\UpdateWaterConfigRequest;
use App\Http\Resources\WaterConfigResource;
use App\Models\WaterConfig;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class WaterConfigController extends Controller
{
    use PaginatesResources;
    use RespondsWithResource;

    public function index(IndexWaterConfigRequest $request): JsonResponse
    {
        $data = $request->validated();
        $query = WaterConfig::query()->orderBy('created_at', 'desc');

        if (array_key_exists('is_active', $data)) {
            $query->where('is_active', (bool) $data['is_active']);
        }

        if (! empty($data['unit'])) {
            $query->where('unit', $data['unit']);
        }

        return $this->paginatedResponse(
            $query->paginate($data['per_page'] ?? 25),
            WaterConfigResource::class,
        );
    }

    public function store(StoreWaterConfigRequest $request): JsonResponse
    {
        $config = WaterConfig::create([
            ...$request->validated(),
            'is_active' => $request->boolean('is_active', true),
            'created_by' => $request->user()?->id,
            'updated_by' => $request->user()?->id,
        ]);

        return $this->resourceResponse(WaterConfigResource::class, $config, 201);
    }

    public function show(WaterConfig $waterConfig): JsonResponse
    {
        return $this->resourceResponse(WaterConfigResource::class, $waterConfig);
    }

    public function update(UpdateWaterConfigRequest $request, WaterConfig $waterConfig): JsonResponse
    {
        $waterConfig->fill([
            ...$request->validated(),
            'updated_by' => $request->user()?->id,
        ])->save();

        return $this->resourceResponse(WaterConfigResource::class, $waterConfig->refresh());
    }

    public function destroy(Request $request, WaterConfig $waterConfig): JsonResponse
    {
        try {
            $waterConfig->delete();
            return response()->json(['data' => ['id' => $waterConfig->id, 'deleted' => true]]);
        } catch (\Illuminate\Database\QueryException $e) {
            $waterConfig->forceFill([
                'is_active' => false,
                'updated_by' => $request->user()?->id,
            ])->save();
            return response()->json([
                'error' => [
                    'code' => 'in_use',
                    'message' => "La configuration eau est utilisée — elle a été désactivée.",
                ],
            ], 409);
        }
    }
}
