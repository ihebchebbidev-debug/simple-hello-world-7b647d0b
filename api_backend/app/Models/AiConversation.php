<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

final class AiConversation extends Model
{
    use HasUuids;

    protected $table = 'ai_conversations';

    protected $fillable = ['id', 'user_id', 'title', 'messages'];

    protected $casts = [
        'messages' => 'array',
    ];
}