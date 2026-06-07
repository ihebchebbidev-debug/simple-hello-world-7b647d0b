<?php

declare(strict_types=1);

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @property-read \App\Models\Plot $resource
 */
final class PlotResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $plot = $this->resource;

        return [
            'id' => $plot->id,
            'name' => $plot->name,
            'surface_area_ha' => (float) $plot->surface_area_ha,
            'crop_type' => $plot->crop_type,
            'variety' => $plot->variety,
            'season_start_date' => $plot->season_start_date?->toDateString(),
            'is_active' => (bool) $plot->is_active,
            'created_at' => $plot->created_at,
            'updated_at' => $plot->updated_at,
        ];
    }
}
