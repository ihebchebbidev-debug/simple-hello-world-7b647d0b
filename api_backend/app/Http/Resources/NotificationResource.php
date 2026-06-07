<?php

declare(strict_types=1);

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @property-read \App\Models\Notification $resource
 */
final class NotificationResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $n = $this->resource;

        return [
            'id'         => $n->id,
            'user_id'    => $n->user_id,
            'type'       => $n->type,
            'title'      => $n->title,
            'body'       => $n->body,
            'data'       => $n->data,
            'read_at'    => $n->read_at,
            'is_read'    => $n->read_at !== null,
            'created_at' => $n->created_at,
        ];
    }
}
