<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class IrrigationOperation extends Model
{
    use HasFactory;
    use HasUuids;

    protected $table = 'irrigation_operations';

    protected $fillable = [
        'plot_id',
        'campaign_id',
        'operation_date',
        'water_quantity',
        'unit_at_entry',
        'price_at_entry',
        'posting_id',
        'created_by',
        'updated_by',
    ];

    protected function casts(): array
    {
        return [
            'operation_date' => 'date:Y-m-d',
            'water_quantity' => 'decimal:2',
            'price_at_entry' => 'decimal:4',
        ];
    }

    public function plot(): BelongsTo
    {
        return $this->belongsTo(Plot::class);
    }
}
