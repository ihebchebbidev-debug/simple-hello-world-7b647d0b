<?php

declare(strict_types=1);

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @property-read \App\Models\IrrigationOperation $resource
 */
final class IrrigationOperationResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $op = $this->resource;

        return [
            'id'             => $op->id,
            'plot_id'        => $op->plot_id,
            'plot'           => $op->relationLoaded('plot') && $op->plot
                ? ['id' => $op->plot->id, 'name' => $op->plot->name, 'surface_area_ha' => (float) $op->plot->surface_area_ha]
                : null,
            'operation_date' => $op->operation_date?->toDateString(),
            'water_quantity' => (float) $op->water_quantity,
            'unit_at_entry'  => $op->unit_at_entry,
            'price_at_entry' => (float) $op->price_at_entry,
            'posting_id'     => $op->posting_id,
            'created_at'     => $op->created_at,
            'updated_at'     => $op->updated_at,
        ];
    }
}
