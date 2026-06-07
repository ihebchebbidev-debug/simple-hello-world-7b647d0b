<?php

declare(strict_types=1);

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @property-read \App\Models\LaborConfig $resource
 */
final class LaborConfigResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $config = $this->resource;

        return [
            'id' => $config->id,
            'is_active' => (bool) $config->is_active,
            'created_at' => $config->created_at,
            'updated_at' => $config->updated_at,
        ];
    }
}
