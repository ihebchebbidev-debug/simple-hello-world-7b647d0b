<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class PhytosanitaryOperation extends Model
{
    use HasFactory;
    use HasUuids;

    protected $table = 'phytosanitary_operations';

    protected $fillable = [
        'plot_id',
        'campaign_id',
        'pesticide_id',
        'operation_date',
        'quantity_applied',
        'water_volume_l',
        'target_pest',
        'remarks',
        'price_at_entry',
        'posting_id',
        'created_by',
        'updated_by',
    ];

    protected function casts(): array
    {
        return [
            'operation_date'   => 'date:Y-m-d',
            'quantity_applied' => 'decimal:3',
            'water_volume_l'   => 'decimal:3',
            'price_at_entry'   => 'decimal:4',
        ];
    }

    public function plot(): BelongsTo
    {
        return $this->belongsTo(Plot::class);
    }

    public function pesticide(): BelongsTo
    {
        return $this->belongsTo(Pesticide::class);
    }
}
