<?php

declare(strict_types=1);

namespace App\Enums;

enum OperationType: string
{
    case Irrigation    = 'irrigation';
    case Fertilization = 'fertilization';
    case Phytosanitary = 'phytosanitary';
    case Harvest       = 'harvest';

    /** @return array<int, string> */
    public static function values(): array
    {
        return array_map(static fn (self $t): string => $t->value, self::cases());
    }
}
