<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class FertilizationOperation extends Model
{
    use HasFactory;
    use HasUuids;

    protected $table = 'fertilization_operations';

    protected $fillable = [
        'plot_id',
        'campaign_id',
        'fertilizer_id',
        'operation_date',
        'quantity_applied',
        'n_at_entry',
        'p_at_entry',
        'k_at_entry',
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
            'n_at_entry'       => 'decimal:2',
            'p_at_entry'       => 'decimal:2',
            'k_at_entry'       => 'decimal:2',
            'price_at_entry'   => 'decimal:4',
        ];
    }

    public function plot(): BelongsTo
    {
        return $this->belongsTo(Plot::class);
    }

    public function fertilizer(): BelongsTo
    {
        return $this->belongsTo(Fertilizer::class);
    }
}
