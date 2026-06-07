<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

final class PriceHistory extends Model
{
    use HasFactory;
    use HasUuids;

    public const ENTITY_TYPES = ['water', 'fertilizer', 'pesticide', 'labor'];

    protected $table = 'price_history';

    public $timestamps = false;

    protected $fillable = [
        'entity_type',
        'entity_id',
        'price_per_unit',
        'unit',
        'effective_from',
        'effective_to',
        'created_at',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'price_per_unit' => 'decimal:4',
            'effective_from' => 'date:Y-m-d',
            'effective_to' => 'date:Y-m-d',
            'created_at' => 'datetime',
        ];
    }
}
