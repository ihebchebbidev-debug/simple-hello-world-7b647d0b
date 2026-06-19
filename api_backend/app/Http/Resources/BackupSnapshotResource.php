<?php

declare(strict_types=1);

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @property-read \App\Models\BackupSnapshot $resource
 */
final class BackupSnapshotResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $s = $this->resource;

        return [
            'id'         => $s->id,
            'label'      => $s->label,
            'status'     => $s->status,
            'size_bytes' => (int) $s->size_bytes,
            'metadata'   => $s->metadata,
            'notes'      => $s->notes,
            'created_by' => $s->creator ? [
                'id'   => $s->creator->id,
                'name' => $s->creator->name,
            ] : null,
            'created_at' => $s->created_at?->toISOString(),
            'updated_at' => $s->updated_at?->toISOString(),
            // snapshot_data is intentionally omitted — it's large and never needed by the UI
        ];
    }
}
