<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class BackupSnapshot extends Model
{
    use HasUuids;

    protected $fillable = [
        'label',
        'created_by',
        'size_bytes',
        'status',
        'notes',
        'metadata',
        'snapshot_data',
    ];

    protected function casts(): array
    {
        return [
            'metadata'   => 'array',
            'size_bytes' => 'integer',
            // snapshot_data intentionally NOT cast — it's a large JSON string
            // that we decode manually only when needed (restore operation).
        ];
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
