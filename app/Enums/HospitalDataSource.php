<?php

namespace App\Enums;

enum HospitalDataSource: string
{
    case Development = 'development';
    case Verified = 'verified';

    /**
     * Marker for the records currently loaded into MediGuide.
     *
     * An unrecognized value stays development so placeholder clinics are
     * never presented as verified hospital information by accident.
     */
    public static function current(): self
    {
        $configured = config('hospital.data_source');

        if (! is_string($configured)) {
            return self::Development;
        }

        return self::tryFrom($configured) ?? self::Development;
    }
}
