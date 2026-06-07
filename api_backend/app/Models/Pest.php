<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * Pest catalogue entry used by mobile autocompletes and report filters.
 */
final class Pest extends Model
{
    use HasFactory;
    use HasUuids;

    protected $fillable = [
        'name',
        'scientific_name',
        'category',
        'description',
        'is_active',
        'created_by',
        'updated_by',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
        ];
    }
}
