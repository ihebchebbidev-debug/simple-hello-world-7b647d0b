<?php

/**
 * Pest catalogue endpoints.
 *
 * Used by mobile autocompletes when recording phytosanitary treatments
 * and by report filters. Admins manage; managers + technicians read.
 */

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Concerns\PaginatesResources;
use App\Http\Controllers\Concerns\RespondsWithResource;
use App\Http\Controllers\Controller;
use App\Http\Requests\Pest\IndexPestRequest;
use App\Http\Requests\Pest\StorePestRequest;
use App\Http\Requests\Pest\UpdatePestRequest;
use App\Http\Resources\PestResource;
use App\Models\Pest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class PestController extends Controller
{
    use PaginatesResources;
    use RespondsWithResource;

    public function index(IndexPestRequest $request): JsonResponse
    {
        $data  = $request->validated();
        $query = Pest::query()->orderBy('name');

        if (array_key_exists('is_active', $data)) {
            $query->where('is_active', (bool) $data['is_active']);
        }

        if (! empty($data['category'])) {
            $query->where('category', $data['category']);
        }

        if (! empty($data['search'])) {
            $search = strtolower($data['search']);
            $query->where(function ($q) use ($search): void {
                $q->whereRaw('LOWER(name) LIKE ?', ["%{$search}%"])
                  ->orWhereRaw('LOWER(COALESCE(scientific_name, \'\')) LIKE ?', ["%{$search}%"]);
            });
        }

        return $this->paginatedResponse(
            $query->paginate($data['per_page'] ?? 25),
            PestResource::class,
        );
    }

    public function store(StorePestRequest $request): JsonResponse
    {
        $pest = Pest::create([
            ...$request->validated(),
            'is_active'  => $request->boolean('is_active', true),
            'created_by' => $request->user()?->id,
            'updated_by' => $request->user()?->id,
        ]);

        return $this->resourceResponse(PestResource::class, $pest, 201);
    }

    public function show(Pest $pest): JsonResponse
    {
        return $this->resourceResponse(PestResource::class, $pest);
    }

    public function update(UpdatePestRequest $request, Pest $pest): JsonResponse
    {
        $pest->fill([
            ...$request->validated(),
            'updated_by' => $request->user()?->id,
        ])->save();

        return $this->resourceResponse(PestResource::class, $pest->refresh());
    }

    public function destroy(Request $request, Pest $pest): JsonResponse
    {
        try {
            $pest->delete();
            return response()->json(['data' => ['id' => $pest->id, 'deleted' => true]]);
        } catch (\Illuminate\Database\QueryException $e) {
            $pest->forceFill([
                'is_active'  => false,
                'updated_by' => $request->user()?->id,
            ])->save();
            return response()->json([
                'error' => [
                    'code' => 'in_use',
                    'message' => "Le bioagresseur est utilisé — il a été désactivé.",
                ],
            ], 409);
        }
    }
}
