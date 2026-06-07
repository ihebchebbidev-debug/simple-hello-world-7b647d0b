<?php

declare(strict_types=1);

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @property-read \App\Models\Fertilizer $resource
 */
final class FertilizerResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $fertilizer = $this->resource;

        return [
            'id' => $fertilizer->id,
            'name' => $fertilizer->name,
            'unit' => $fertilizer->unit,
            'n_percent' => (float) $fertilizer->n_percent,
            'p_percent' => (float) $fertilizer->p_percent,
            'k_percent' => (float) $fertilizer->k_percent,
            'is_active' => (bool) $fertilizer->is_active,
            'created_at' => $fertilizer->created_at,
            'updated_at' => $fertilizer->updated_at,
        ];
    }
}
