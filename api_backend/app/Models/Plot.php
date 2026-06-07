<?php

/**
 * Plot model.
 *
 * Represents a farm plot used across configuration, field operations, and
 * season-based reporting calculations.
 */

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

final class Plot extends Model
{
    use HasFactory;
    use HasUuids;

    protected $fillable = [
        'name',
        'surface_area_ha',
        'crop_type',
        'variety',
        'season_start_date',
        'is_active',
        'created_by',
        'updated_by',
    ];

    protected function casts(): array
    {
        return [
            'surface_area_ha'   => 'decimal:4',
            'season_start_date' => 'date:Y-m-d',
            'is_active'         => 'boolean',
        ];
    }
}
