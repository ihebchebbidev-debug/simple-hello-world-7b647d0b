<?php

/**
 * Labor configuration endpoints.
 *
 * Tracks active labor configurations. Daily rates live in the price_history
 * table with entity_type=labor.
 */

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Concerns\PaginatesResources;
use App\Http\Controllers\Concerns\RespondsWithResource;
use App\Http\Controllers\Controller;
use App\Http\Requests\LaborConfig\IndexLaborConfigRequest;
use App\Http\Requests\LaborConfig\StoreLaborConfigRequest;
use App\Http\Requests\LaborConfig\UpdateLaborConfigRequest;
use App\Http\Resources\LaborConfigResource;
use App\Models\LaborConfig;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class LaborConfigController extends Controller
{
    use PaginatesResources;
    use RespondsWithResource;

    public function index(IndexLaborConfigRequest $request): JsonResponse
    {
        $data = $request->validated();
        $query = LaborConfig::query()->orderBy('created_at', 'desc');

        if (array_key_exists('is_active', $data)) {
            $query->where('is_active', (bool) $data['is_active']);
        }

        return $this->paginatedResponse(
            $query->paginate($data['per_page'] ?? 25),
            LaborConfigResource::class,
        );
    }

    public function store(StoreLaborConfigRequest $request): JsonResponse
    {
        $config = LaborConfig::create([
            ...$request->validated(),
            'is_active' => $request->boolean('is_active', true),
            'created_by' => $request->user()?->id,
            'updated_by' => $request->user()?->id,
        ]);

        return $this->resourceResponse(LaborConfigResource::class, $config, 201);
    }

    public function show(LaborConfig $laborConfig): JsonResponse
    {
        return $this->resourceResponse(LaborConfigResource::class, $laborConfig);
    }

    public function update(UpdateLaborConfigRequest $request, LaborConfig $laborConfig): JsonResponse
    {
        $laborConfig->fill([
            ...$request->validated(),
            'updated_by' => $request->user()?->id,
        ])->save();

        return $this->resourceResponse(LaborConfigResource::class, $laborConfig->refresh());
    }

    public function destroy(Request $request, LaborConfig $laborConfig): JsonResponse
    {
        try {
            $laborConfig->delete();
            return response()->json(['data' => ['id' => $laborConfig->id, 'deleted' => true]]);
        } catch (\Illuminate\Database\QueryException $e) {
            $laborConfig->forceFill([
                'is_active' => false,
                'updated_by' => $request->user()?->id,
            ])->save();
            return response()->json([
                'error' => [
                    'code' => 'in_use',
                    'message' => "La configuration main d'oeuvre est utilisée — elle a été désactivée.",
                ],
            ], 409);
        }
    }
}
