<?php

declare(strict_types=1);

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @property-read \App\Models\PhytosanitaryOperation $resource
 */
final class PhytosanitaryOperationResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $op = $this->resource;

        return [
            'id'               => $op->id,
            'plot_id'          => $op->plot_id,
            'plot'             => $op->relationLoaded('plot') && $op->plot
                ? ['id' => $op->plot->id, 'name' => $op->plot->name, 'surface_area_ha' => (float) $op->plot->surface_area_ha]
                : null,
            'pesticide_id'     => $op->pesticide_id,
            'pesticide'        => $op->relationLoaded('pesticide') && $op->pesticide
                ? [
                    'id'                   => $op->pesticide->id,
                    'name'                 => $op->pesticide->name,
                    'unit'                 => $op->pesticide->unit,
                    'chemical_composition' => $op->pesticide->chemical_composition,
                  ]
                : null,
            'operation_date'   => $op->operation_date?->toDateString(),
            'quantity_applied' => (float) $op->quantity_applied,
            'water_volume_l'   => $op->water_volume_l !== null ? (float) $op->water_volume_l : null,
            'target_pest'      => $op->target_pest,
            'remarks'          => $op->remarks,
            'price_at_entry'   => (float) $op->price_at_entry,
            'posting_id'       => $op->posting_id,
            'created_at'       => $op->created_at,
            'updated_at'       => $op->updated_at,
        ];
    }
}
