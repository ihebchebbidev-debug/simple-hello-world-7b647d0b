<?php

declare(strict_types=1);

namespace App\Enums;

enum PostingStatus: string
{
    case Pending  = 'pending';
    case Synced   = 'synced';
    case Failed   = 'failed';
    case Conflict = 'conflict';
}
