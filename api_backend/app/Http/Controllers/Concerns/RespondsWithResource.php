<?php

declare(strict_types=1);

namespace App\Http\Controllers\Concerns;

use App\Support\Http\ApiResponse;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\JsonResource;

trait RespondsWithResource
{
    /**
     * Wrap a single model in our standard `{ data: ... }` envelope.
     *
     * @param  class-string<JsonResource>  $resource
     */
    protected function resourceResponse(string $resource, Model $model, int $status = 200): JsonResponse
    {
        return ApiResponse::ok($resource::make($model)->resolve(), $status);
    }
}
