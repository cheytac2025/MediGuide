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
            self::Pending => 'Pending Hospital Confirmation',
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

    public function canStaffConfirm(): bool
    {
        return $this === self::Pending;
    }

    public function canStaffReject(): bool
    {
        return $this === self::Pending;
    }

    public function canStaffReschedule(): bool
    {
        return $this->isUpcoming();
    }

    /**
     * Assigned doctors may mark a confirmed consultation complete.
     */
    public function canDoctorComplete(): bool
    {
        return $this === self::Confirmed;
    }

    /**
     * Patient instructions may publish only after a completed consultation.
     */
    public function allowsPostConsultationInstructions(): bool
    {
        return $this === self::Completed;
    }
}
