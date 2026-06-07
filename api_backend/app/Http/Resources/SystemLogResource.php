<?php

declare(strict_types=1);

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @property-read \App\Models\SystemLog $resource
 */
final class SystemLogResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $log = $this->resource;

        return [
            'id'          => (int) $log->id,
            'level'       => $log->level,
            'category'    => $log->category,
            'action'      => $log->action,
            'entity_type' => $log->entity_type,
            'entity_id'   => $log->entity_id,
            'details'     => $log->details,
            'ip_address'  => $log->ip_address,
            'user_agent'  => $log->user_agent,
            'user_id'     => $log->user_id,
            'created_at'  => $log->created_at,
        ];
    }
}
