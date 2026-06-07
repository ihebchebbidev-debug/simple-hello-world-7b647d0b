<?php

/**
 * Pesticide configuration endpoints.
 *
 * Admins can create, update, and deactivate pesticide references.
 * Managers and technicians have read access for reporting and field workflows.
 */

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Concerns\PaginatesResources;
use App\Http\Controllers\Concerns\RespondsWithResource;
use App\Http\Controllers\Controller;
use App\Http\Requests\Pesticide\IndexPesticideRequest;
use App\Http\Requests\Pesticide\StorePesticideRequest;
use App\Http\Requests\Pesticide\UpdatePesticideRequest;
use App\Http\Resources\PesticideResource;
use App\Models\Pesticide;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class PesticideController extends Controller
{
    use PaginatesResources;
    use RespondsWithResource;

    public function index(IndexPesticideRequest $request): JsonResponse
    {
        $data = $request->validated();
        $query = Pesticide::query()->orderBy('name');

        if (array_key_exists('is_active', $data)) {
            $query->where('is_active', (bool) $data['is_active']);
        }

        if (! empty($data['unit'])) {
            $query->where('unit', $data['unit']);
        }

        if (! empty($data['search'])) {
            $search = strtolower($data['search']);
            $query->where(function ($q) use ($search): void {
                $q->whereRaw('LOWER(name) LIKE ?', ["%{$search}%"])
                    ->orWhereRaw('LOWER(chemical_composition) LIKE ?', ["%{$search}%"]);
            });
        }

        return $this->paginatedResponse(
            $query->paginate($data['per_page'] ?? 25),
            PesticideResource::class,
        );
    }

    public function store(StorePesticideRequest $request): JsonResponse
    {
        $pesticide = Pesticide::create([
            ...$request->validated(),
            'is_active' => $request->boolean('is_active', true),
            'created_by' => $request->user()?->id,
            'updated_by' => $request->user()?->id,
        ]);

        return $this->resourceResponse(PesticideResource::class, $pesticide, 201);
    }

    public function show(Pesticide $pesticide): JsonResponse
    {
        return $this->resourceResponse(PesticideResource::class, $pesticide);
    }

    public function update(UpdatePesticideRequest $request, Pesticide $pesticide): JsonResponse
    {
        $pesticide->fill([
            ...$request->validated(),
            'updated_by' => $request->user()?->id,
        ])->save();

        return $this->resourceResponse(PesticideResource::class, $pesticide->refresh());
    }

    public function destroy(Request $request, Pesticide $pesticide): JsonResponse
    {
        // Customer feedback: "Supprimer" must actually delete, not deactivate.
        try {
            $pesticide->delete();
            return response()->json(['data' => ['id' => $pesticide->id, 'deleted' => true]]);
        } catch (\Illuminate\Database\QueryException $e) {
            $pesticide->forceFill([
                'is_active' => false,
                'updated_by' => $request->user()?->id,
            ])->save();
            return response()->json([
                'error' => [
                    'code' => 'in_use',
                    'message' => "Le pesticide est utilisé par des opérations existantes — il a été désactivé.",
                ],
            ], 409);
        }
    }
}
