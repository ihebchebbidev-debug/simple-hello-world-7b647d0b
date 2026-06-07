<?php

/**
 * Plot configuration endpoints.
 *
 * Admins can create/update/deactivate plots. Managers and technicians have
 * read access for reporting and field workflows.
 */

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Concerns\PaginatesResources;
use App\Http\Controllers\Concerns\RespondsWithResource;
use App\Http\Controllers\Controller;
use App\Http\Requests\Plot\IndexPlotRequest;
use App\Http\Requests\Plot\StorePlotRequest;
use App\Http\Requests\Plot\UpdatePlotRequest;
use App\Http\Resources\PlotResource;
use App\Models\Plot;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class PlotController extends Controller
{
    use PaginatesResources;
    use RespondsWithResource;

    public function index(IndexPlotRequest $request): JsonResponse
    {
        $data = $request->validated();
        $query = Plot::query()->orderBy('name');

        if (array_key_exists('is_active', $data)) {
            $query->where('is_active', (bool) $data['is_active']);
        }

        if (! empty($data['crop_type'])) {
            $query->where('crop_type', $data['crop_type']);
        }

        if (! empty($data['search'])) {
            $search = strtolower($data['search']);
            $query->where(function ($q) use ($search): void {
                $q->whereRaw('LOWER(name) LIKE ?', ["%{$search}%"])
                    ->orWhereRaw('LOWER(crop_type) LIKE ?', ["%{$search}%"])
                    ->orWhereRaw('LOWER(variety) LIKE ?', ["%{$search}%"]);
            });
        }

        return $this->paginatedResponse(
            $query->paginate($data['per_page'] ?? 25),
            PlotResource::class,
        );
    }

    public function store(StorePlotRequest $request): JsonResponse
    {
        $plot = Plot::create([
            ...$request->validated(),
            'is_active' => $request->boolean('is_active', true),
            'created_by' => $request->user()?->id,
            'updated_by' => $request->user()?->id,
        ]);

        return $this->resourceResponse(PlotResource::class, $plot, 201);
    }

    public function show(Plot $plot): JsonResponse
    {
        return $this->resourceResponse(PlotResource::class, $plot);
    }

    public function update(UpdatePlotRequest $request, Plot $plot): JsonResponse
    {
        $plot->fill([
            ...$request->validated(),
            'updated_by' => $request->user()?->id,
        ])->save();

        return $this->resourceResponse(PlotResource::class, $plot->refresh());
    }

    public function destroy(Request $request, Plot $plot): JsonResponse
    {
        // Customer feedback: "Supprimer" must actually delete, not deactivate.
        // Fall back to soft-deactivate when FK references make hard delete unsafe.
        try {
            $plot->delete();
            return response()->json(['data' => ['id' => $plot->id, 'deleted' => true]]);
        } catch (\Illuminate\Database\QueryException $e) {
            $plot->forceFill([
                'is_active' => false,
                'updated_by' => $request->user()?->id,
            ])->save();
            return response()->json([
                'error' => [
                    'code' => 'in_use',
                    'message' => "La parcelle est utilisée par des opérations existantes — elle a été désactivée.",
                ],
            ], 409);
        }
    }
}
