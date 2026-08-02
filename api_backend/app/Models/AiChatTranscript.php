<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

final class AiChatTranscript extends Model
{
    use HasUuids;

    protected $table = 'ai_chat_transcripts';

    protected $fillable = [
        'id', 'conversation_id', 'user_id', 'user_label', 'locale', 'streamed',
        'status', 'error_code', 'question', 'answer', 'duration_ms', 'ip',
    ];

    protected $casts = [
        'streamed' => 'boolean',
    ];
}
