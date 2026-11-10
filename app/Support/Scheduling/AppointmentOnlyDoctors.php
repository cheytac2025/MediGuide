<?php

namespace App\Support\Scheduling;

use App\Models\Doctor;
use App\Support\HospitalImport\QueenMaryClinicScheduleCatalog;

/**
 * Doctors the hospital catalog lists as appointment-only do not receive
 * ordinary public slots from staff schedule management.
 */
final class AppointmentOnlyDoctors
{
    public static function excludes(Doctor $doctor): bool
    {
        $name = mb_strtolower(trim($doctor->display_name));

        foreach (QueenMaryClinicScheduleCatalog::doctors() as $record) {
            $catalogName = mb_strtolower(trim((string) ($record['display_name'] ?? '')));

            if ($catalogName !== $name) {
                continue;
            }

            return in_array((string) ($record['booking_mode'] ?? ''), ['by_appointment', 'strictly_by_appointment'], true);
        }

        return false;
    }
}
