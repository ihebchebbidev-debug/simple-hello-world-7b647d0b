<?php

declare(strict_types=1);

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @property-read \App\Models\FertilizationOperation $resource
 */
final class FertilizationOperationResource extends JsonResource
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
            'fertilizer_id'    => $op->fertilizer_id,
            'fertilizer'       => $op->relationLoaded('fertilizer') && $op->fertilizer
                ? ['id' => $op->fertilizer->id, 'name' => $op->fertilizer->name, 'unit' => $op->fertilizer->unit]
                : null,
            'operation_date'   => $op->operation_date?->toDateString(),
            'quantity_applied' => (float) $op->quantity_applied,
            'n_at_entry'       => (float) $op->n_at_entry,
            'p_at_entry'       => (float) $op->p_at_entry,
            'k_at_entry'       => (float) $op->k_at_entry,
            'price_at_entry'   => (float) $op->price_at_entry,
            'posting_id'       => $op->posting_id,
            'created_at'       => $op->created_at,
            'updated_at'       => $op->updated_at,
        ];
    }
}
