<?php

namespace App\Enums;

enum RoleName: string
{
    case Patient = 'patient';
    case HospitalStaff = 'hospital-staff';
    case Doctor = 'doctor';
    case ItAdministrator = 'it-administrator';

    public function label(): string
    {
        return match ($this) {
            self::Patient => 'PATIENT',
            self::HospitalStaff => 'HOSPITAL STAFF',
            self::Doctor => 'DOCTOR',
            self::ItAdministrator => 'IT ADMINISTRATOR',
        };
    }
}
