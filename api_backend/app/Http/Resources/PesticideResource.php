<?php

declare(strict_types=1);

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @property-read \App\Models\Pesticide $resource
 */
final class PesticideResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $pesticide = $this->resource;

        return [
            'id' => $pesticide->id,
            'name' => $pesticide->name,
            'unit' => $pesticide->unit,
            'chemical_composition' => $pesticide->chemical_composition,
            'is_active' => (bool) $pesticide->is_active,
            'created_at' => $pesticide->created_at,
            'updated_at' => $pesticide->updated_at,
        ];
    }
}
