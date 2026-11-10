<?php

namespace App\Enums;

enum AppointmentMessageKind: string
{
    case ApprovalNote = 'approval_note';
    case RejectionReason = 'rejection_reason';
    case RescheduleReason = 'reschedule_reason';

    public function label(): string
    {
        return match ($this) {
            self::ApprovalNote => 'Approval note',
            self::RejectionReason => 'Rejection reason',
            self::RescheduleReason => 'Rescheduled',
        };
    }
}
