<?php

/**
 * Campaign configuration endpoints.
 *
 * Campaigns scope operations and reports to a season window. Admins can
 * create/update/deactivate campaigns; managers + technicians read.
 */

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Concerns\PaginatesResources;
use App\Http\Controllers\Concerns\RespondsWithResource;
use App\Http\Controllers\Controller;
use App\Http\Requests\Campaign\IndexCampaignRequest;
use App\Http\Requests\Campaign\StoreCampaignRequest;
use App\Http\Requests\Campaign\UpdateCampaignRequest;
use App\Http\Resources\CampaignResource;
use App\Models\Campaign;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class CampaignController extends Controller
{
    use PaginatesResources;
    use RespondsWithResource;

    public function index(IndexCampaignRequest $request): JsonResponse
    {
        $data  = $request->validated();
        $query = Campaign::query()->orderByDesc('start_date');

        if (array_key_exists('is_active', $data)) {
            $query->where('is_active', (bool) $data['is_active']);
        }

        if (! empty($data['search'])) {
            $search = strtolower($data['search']);
            $query->whereRaw('LOWER(name) LIKE ?', ["%{$search}%"]);
        }

        return $this->paginatedResponse(
            $query->paginate($data['per_page'] ?? 25),
            CampaignResource::class,
        );
    }

    public function store(StoreCampaignRequest $request): JsonResponse
    {
        $campaign = Campaign::create([
            ...$request->validated(),
            'is_active'  => $request->boolean('is_active', true),
            'created_by' => $request->user()?->id,
            'updated_by' => $request->user()?->id,
        ]);

        return $this->resourceResponse(CampaignResource::class, $campaign, 201);
    }

    public function show(Campaign $campaign): JsonResponse
    {
        return $this->resourceResponse(CampaignResource::class, $campaign);
    }

    public function update(UpdateCampaignRequest $request, Campaign $campaign): JsonResponse
    {
        $campaign->fill([
            ...$request->validated(),
            'updated_by' => $request->user()?->id,
        ])->save();

        return $this->resourceResponse(CampaignResource::class, $campaign->refresh());
    }

    public function destroy(Request $request, Campaign $campaign): JsonResponse
    {
        // Hard delete the campaign. Operations linked via campaign_id use ON DELETE
        // SET NULL / RESTRICT at the schema level — if a RESTRICT triggers, surface a
        // 409 instead of a 500 so the UI can show a meaningful message.
        try {
            $campaign->delete();
        } catch (\Illuminate\Database\QueryException $e) {
            return response()->json([
                'error' => [
                    'code'    => 'campaign_in_use',
                    'message' => __('errors.campaign_in_use'),
                ],
            ], 409);
        }

        return response()->json(null, 204);
    }

}
