<?php

namespace App\Enums;

enum DepartmentStatus: string
{
    case Active = 'active';
    case Inactive = 'inactive';

    public function label(): string
    {
        return match ($this) {
            self::Active => 'ACTIVE',
            self::Inactive => 'INACTIVE',
        };
    }
}
