<?php

/**
 * Fertilizer configuration endpoints.
 *
 * Admins can create/update/deactivate fertilizer references with N/P/K
 * percentage composition. Managers and technicians have read access.
 */

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Concerns\PaginatesResources;
use App\Http\Controllers\Concerns\RespondsWithResource;
use App\Http\Controllers\Controller;
use App\Http\Requests\Fertilizer\IndexFertilizerRequest;
use App\Http\Requests\Fertilizer\StoreFertilizerRequest;
use App\Http\Requests\Fertilizer\UpdateFertilizerRequest;
use App\Http\Resources\FertilizerResource;
use App\Models\Fertilizer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

final class FertilizerController extends Controller
{
    use PaginatesResources;
    use RespondsWithResource;

    public function index(IndexFertilizerRequest $request): JsonResponse
    {
        $data = $request->validated();
        $query = Fertilizer::query()->orderBy('name');

        if (array_key_exists('is_active', $data)) {
            $query->where('is_active', (bool) $data['is_active']);
        }

        if (! empty($data['unit'])) {
            $query->where('unit', $data['unit']);
        }

        if (! empty($data['search'])) {
            $search = strtolower($data['search']);
            $query->whereRaw('LOWER(name) LIKE ?', ["%{$search}%"]);
        }

        return $this->paginatedResponse(
            $query->paginate($data['per_page'] ?? 25),
            FertilizerResource::class,
        );
    }

    public function store(StoreFertilizerRequest $request): JsonResponse
    {
        $fertilizer = Fertilizer::create([
            ...$request->validated(),
            'is_active' => $request->boolean('is_active', true),
            'created_by' => $request->user()?->id,
            'updated_by' => $request->user()?->id,
        ]);

        return $this->resourceResponse(FertilizerResource::class, $fertilizer, 201);
    }

    public function show(Fertilizer $fertilizer): JsonResponse
    {
        return $this->resourceResponse(FertilizerResource::class, $fertilizer);
    }

    public function update(UpdateFertilizerRequest $request, Fertilizer $fertilizer): JsonResponse
    {
        $data = $request->validated();

        // Capture the pre-edit composition so we only propagate genuine changes.
        $original = [
            'n_percent' => (float) $fertilizer->n_percent,
            'p_percent' => (float) $fertilizer->p_percent,
            'k_percent' => (float) $fertilizer->k_percent,
            'mg_percent' => (float) $fertilizer->mg_percent,
            'ca_percent' => (float) $fertilizer->ca_percent,
            's_percent' => (float) $fertilizer->s_percent,
        ];

        $fertilizer->fill([
            ...$data,
            'updated_by' => $request->user()?->id,
        ])->save();

        // N/P/K % is a fixed product property, NOT a time-varying value like
        // price. When the admin *corrects* a mis-entered composition, the fix
        // must also flow into the *_at_entry snapshots of operations already
        // recorded — otherwise the fertilization report keeps reporting the
        // wrong NPK for past entries (client feedback: Solupotasse P↔K mix-up).
        // Price stays snapshotted (historical cost must not change); only the
        // composition is back-filled.
        $map = [
            'n_percent' => 'n_at_entry',
            'p_percent' => 'p_at_entry',
            'k_percent' => 'k_at_entry',
            'mg_percent' => 'mg_at_entry',
            'ca_percent' => 'ca_at_entry',
            's_percent' => 's_at_entry',
        ];
        $propagate = [];
        foreach ($map as $src => $dst) {
            if (array_key_exists($src, $data) && (float) $data[$src] !== $original[$src]) {
                $propagate[$dst] = $data[$src];
            }
        }
        if ($propagate !== []) {
            DB::table('fertilization_operations')
                ->where('fertilizer_id', $fertilizer->id)
                ->update($propagate);
        }

        return $this->resourceResponse(FertilizerResource::class, $fertilizer->refresh());
    }

    public function destroy(Request $request, Fertilizer $fertilizer): JsonResponse
    {
        // Customer feedback: "Supprimer" must actually delete, not deactivate.
        try {
            $fertilizer->delete();
            return response()->json(['data' => ['id' => $fertilizer->id, 'deleted' => true]]);
        } catch (\Illuminate\Database\QueryException $e) {
            $fertilizer->forceFill([
                'is_active' => false,
                'updated_by' => $request->user()?->id,
            ])->save();
            return response()->json([
                'error' => [
                    'code' => 'in_use',
                    'message' => "L'engrais est utilisé par des opérations existantes — il a été désactivé.",
                ],
            ], 409);
        }
    }
}
