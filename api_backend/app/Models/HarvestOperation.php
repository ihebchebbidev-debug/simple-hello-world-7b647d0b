<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class HarvestOperation extends Model
{
    use HasFactory;
    use HasUuids;

    protected $table = 'harvest_operations';

    protected $fillable = [
        'plot_id',
        'campaign_id',
        'operation_date',
        'num_workers',
        'days_worked',
        'quantity_harvested',
        'daily_rate_at_entry',
        'posting_id',
        'created_by',
        'updated_by',
    ];

    protected function casts(): array
    {
        return [
            'operation_date'      => 'date:Y-m-d',
            'num_workers'         => 'integer',
            'days_worked'         => 'decimal:2',
            'quantity_harvested'  => 'decimal:2',
            'daily_rate_at_entry' => 'decimal:4',
        ];
    }

    public function plot(): BelongsTo
    {
        return $this->belongsTo(Plot::class);
    }
}
