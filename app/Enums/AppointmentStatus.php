<?php

namespace App\Enums;

enum AppointmentStatus: string
{
    case Pending = 'pending';
    case Confirmed = 'confirmed';
    case Completed = 'completed';
    case Cancelled = 'cancelled';
    case Rejected = 'rejected';

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'PENDING',
            self::Confirmed => 'CONFIRMED',
            self::Completed => 'COMPLETED',
            self::Cancelled => 'CANCELLED',
            self::Rejected => 'REJECTED',
        };
    }
}
