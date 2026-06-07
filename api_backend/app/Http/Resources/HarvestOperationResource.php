<?php

declare(strict_types=1);

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @property-read \App\Models\HarvestOperation $resource
 */
final class HarvestOperationResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $op = $this->resource;

        return [
            'id'                   => $op->id,
            'plot_id'              => $op->plot_id,
            'plot'                 => $op->relationLoaded('plot') && $op->plot
                ? ['id' => $op->plot->id, 'name' => $op->plot->name, 'surface_area_ha' => (float) $op->plot->surface_area_ha]
                : null,
            'operation_date'       => $op->operation_date?->toDateString(),
            'num_workers'          => (int) $op->num_workers,
            'days_worked'          => (float) $op->days_worked,
            'quantity_harvested'   => (float) $op->quantity_harvested,
            'daily_rate_at_entry'  => (float) $op->daily_rate_at_entry,
            'posting_id'           => $op->posting_id,
            'created_at'           => $op->created_at,
            'updated_at'           => $op->updated_at,
        ];
    }
}
