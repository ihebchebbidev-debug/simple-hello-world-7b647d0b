<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Audit log entry for admin observability.
 *
 * Auto-incrementing BIGINT primary key (not UUID — high write volume).
 * Insert-only: never updated, so timestamps are limited to created_at.
 */
final class SystemLog extends Model
{
    use HasFactory;

    public const LEVELS     = ['info', 'warn', 'error', 'debug'];
    public const CATEGORIES = ['auth', 'sync', 'admin', 'operation', 'system'];

    protected $table = 'system_logs';

    public $timestamps = false;

    protected $fillable = [
        'level',
        'category',
        'action',
        'entity_type',
        'entity_id',
        'details',
        'ip_address',
        'user_agent',
        'user_id',
        'created_at',
    ];

    protected function casts(): array
    {
        return [
            'details'    => 'array',
            'created_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Convenience writer used by controllers / middleware.
     *
     * @param  array<string, mixed>|null  $details
     */
    public static function record(
        string $category,
        string $action,
        ?array $details = null,
        ?string $entityType = null,
        ?string $entityId = null,
        string $level = 'info',
        ?string $userId = null,
        ?string $ipAddress = null,
        ?string $userAgent = null,
    ): self {
        return self::create([
            'level'       => in_array($level, self::LEVELS, true) ? $level : 'info',
            'category'    => $category,
            'action'      => $action,
            'entity_type' => $entityType,
            'entity_id'   => $entityId,
            'details'     => $details,
            'ip_address'  => $ipAddress,
            'user_agent'  => $userAgent,
            'user_id'     => $userId,
            'created_at'  => now(),
        ]);
    }
}
