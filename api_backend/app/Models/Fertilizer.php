<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

final class Fertilizer extends Model
{
    use HasFactory;
    use HasUuids;

    protected $fillable = [
        'name',
        'unit',
        'n_percent',
        'p_percent',
        'k_percent',
        'is_active',
        'created_by',
        'updated_by',
    ];

    protected function casts(): array
    {
        return [
            'n_percent' => 'decimal:2',
            'p_percent' => 'decimal:2',
            'k_percent' => 'decimal:2',
            'is_active' => 'boolean',
        ];
    }
}
