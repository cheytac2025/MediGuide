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

    public function patientLabel(): string
    {
        return match ($this) {
            self::Pending => 'Pending hospital confirmation',
            self::Confirmed => 'Confirmed',
            self::Completed => 'Completed',
            self::Cancelled => 'Cancelled',
            self::Rejected => 'Rejected',
        };
    }

    public function staffLabel(): string
    {
        return match ($this) {
            self::Pending => 'Pending Review',
            self::Confirmed => 'Confirmed',
            self::Completed => 'Completed',
            self::Cancelled => 'Cancelled',
            self::Rejected => 'Rejected',
        };
    }

    public function isUpcoming(): bool
    {
        return $this === self::Pending || $this === self::Confirmed;
    }

    public function canBeCancelledByPatient(): bool
    {
        return $this->isUpcoming();
    }
}
