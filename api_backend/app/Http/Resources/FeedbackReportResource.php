<?php

declare(strict_types=1);

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @property-read \App\Models\FeedbackReport $resource
 */
final class FeedbackReportResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $f = $this->resource;

        return [
            'id'           => $f->id,
            'user_id'      => $f->user_id,
            'user'         => $f->relationLoaded('user') && $f->user
                ? ['id' => $f->user->id, 'name' => $f->user->name, 'email' => $f->user->email]
                : null,
            'type'         => $f->type,
            'severity'     => $f->severity,
            'status'       => $f->status,
            'title'        => $f->title,
            'description'  => $f->description,
            'page_url'     => $f->page_url,
            'user_agent'   => $f->user_agent,
            'app_version'  => $f->app_version,
            'metadata'     => $f->metadata,
            'admin_notes'  => $f->admin_notes,
            'resolved_at'  => $f->resolved_at,
            'resolved_by'  => $f->resolved_by,
            'created_at'   => $f->created_at,
            'updated_at'   => $f->updated_at,
        ];
    }
}
