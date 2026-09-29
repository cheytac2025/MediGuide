<?php

namespace App\Enums;

enum DayOfWeek: string
{
    case Monday = 'monday';
    case Tuesday = 'tuesday';
    case Wednesday = 'wednesday';
    case Thursday = 'thursday';
    case Friday = 'friday';
    case Saturday = 'saturday';
    case Sunday = 'sunday';

    public function label(): string
    {
        return match ($this) {
            self::Monday => 'MONDAY',
            self::Tuesday => 'TUESDAY',
            self::Wednesday => 'WEDNESDAY',
            self::Thursday => 'THURSDAY',
            self::Friday => 'FRIDAY',
            self::Saturday => 'SATURDAY',
            self::Sunday => 'SUNDAY',
        };
    }
}
