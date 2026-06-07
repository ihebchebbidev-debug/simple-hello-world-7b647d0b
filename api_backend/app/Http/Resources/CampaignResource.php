<?php

declare(strict_types=1);

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @property-read \App\Models\Campaign $resource
 */
final class CampaignResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $c = $this->resource;

        return [
            'id'         => $c->id,
            'name'       => $c->name,
            'start_date' => optional($c->start_date)->toDateString() ?? $c->start_date,
            'end_date'   => optional($c->end_date)->toDateString() ?? $c->end_date,
            'is_active'  => (bool) $c->is_active,
            'created_at' => $c->created_at,
            'updated_at' => $c->updated_at,
        ];
    }
}
