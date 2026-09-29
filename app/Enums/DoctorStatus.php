<?php

namespace App\Enums;

enum DoctorStatus: string
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
