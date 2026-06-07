<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * In-app bug / feature report submitted from the admin top bar.
 */
final class FeedbackReport extends Model
{
    use HasFactory;
    use HasUuids;

    protected $table = 'feedback_reports';

    protected $fillable = [
        'user_id',
        'type',
        'severity',
        'status',
        'title',
        'description',
        'page_url',
        'user_agent',
        'app_version',
        'metadata',
        'admin_notes',
        'resolved_at',
        'resolved_by',
    ];

    protected function casts(): array
    {
        return [
            'metadata'    => 'array',
            'resolved_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function resolver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'resolved_by');
    }
}
