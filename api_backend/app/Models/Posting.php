<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\OperationType;
use App\Enums\PostingStatus;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

final class Posting extends Model
{
    use HasFactory;
    use HasUuids;

    protected $table = 'postings';

    public $timestamps = false;

    protected $fillable = [
        'client_id',
        'operation_type',
        'payload',
        'status',
        'error_message',
        'retry_count',
        'device_info',
        'submitted_at',
        'synced_at',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'operation_type' => OperationType::class,
            'status'         => PostingStatus::class,
            'payload'        => 'array',
            'device_info'    => 'array',
            'retry_count'    => 'integer',
            'submitted_at'   => 'datetime',
            'synced_at'      => 'datetime',
        ];
    }
}
