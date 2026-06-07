<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Application role enum.
 *
 * Single source of truth for role names. Used by validation rules and the
 * role middleware so a typo cannot drift between layers.
 */
enum AppRole: string
{
    case Technician = 'technician';
    case Manager = 'manager';
    case Admin = 'admin';

    /** @return array<int, string> */
    public static function values(): array
    {
        return array_map(static fn (self $role): string => $role->value, self::cases());
    }
}
