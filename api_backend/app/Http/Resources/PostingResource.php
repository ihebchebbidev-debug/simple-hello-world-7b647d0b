<?php

declare(strict_types=1);

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @property-read \App\Models\Posting $resource
 */
final class PostingResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $posting = $this->resource;

        return [
            'id'             => $posting->id,
            'client_id'      => $posting->client_id,
            'operation_type' => $posting->operation_type instanceof \BackedEnum
                ? $posting->operation_type->value
                : $posting->operation_type,
            'status'         => $posting->status instanceof \BackedEnum
                ? $posting->status->value
                : $posting->status,
            'error_message'  => $posting->error_message,
            'retry_count'    => (int) $posting->retry_count,
            'device_info'    => $posting->device_info,
            'submitted_at'   => $posting->submitted_at,
            'synced_at'      => $posting->synced_at,
        ];
    }
}
