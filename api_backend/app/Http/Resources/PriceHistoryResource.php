<?php

declare(strict_types=1);

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @property-read \App\Models\PriceHistory $resource
 */
final class PriceHistoryResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $price = $this->resource;

        return [
            'id' => $price->id,
            'entity_type' => $price->entity_type,
            'entity_id' => $price->entity_id,
            'price_per_unit' => (float) $price->price_per_unit,
            'unit' => $price->unit,
            'effective_from' => $price->effective_from?->toDateString(),
            'effective_to' => $price->effective_to?->toDateString(),
            'created_at' => $price->created_at,
            'created_by' => $price->created_by,
        ];
    }
}
