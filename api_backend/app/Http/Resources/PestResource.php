<?php

declare(strict_types=1);

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @property-read \App\Models\Pest $resource
 */
final class PestResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $p = $this->resource;

        return [
            'id'              => $p->id,
            'name'            => $p->name,
            'scientific_name' => $p->scientific_name,
            'category'        => $p->category,
            'description'     => $p->description,
            'is_active'       => (bool) $p->is_active,
            'created_at'      => $p->created_at,
            'updated_at'      => $p->updated_at,
        ];
    }
}
