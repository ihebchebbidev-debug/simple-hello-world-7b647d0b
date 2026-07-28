<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

final class AiFeedback extends Model
{
    use HasUuids;

    protected $table = 'ai_feedback';

    protected $fillable = [
        'id',
        'user_id',
        'conversation_id',
        'message_client_id',
        'rating',
        'locale',
        'comment',
        'question',
        'answer',
        'tags',
    ];

    protected $casts = [
        'tags' => 'array',
    ];
}
