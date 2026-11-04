<?php

namespace App\Support\HospitalImport;

use App\Enums\DayOfWeek;

/**
 * Source facts copied from the Queen Mary Help of Christians Hospital
 * clinic-schedule poster supplied on 4 Nov 2026.
 *
 * Both uploaded images were the same page. Secretary numbers are retained
 * only as extraction notes and are never imported.
 *
 * This catalog is not hospital verification.
 */
final class QueenMaryClinicScheduleCatalog
{
    public const string SOURCE_TITLE = 'Queen Mary Help of Christians Hospital, Inc., Cardona, Rizal — Clinic Schedules';

    public const string SOURCE_NOTE = 'Extracted from the supplied clinic-schedule poster. The poster is not proof that every doctor or hour is currently active.';

    public const string PENDING_DEPARTMENT_NAME = 'Pending hospital mapping';

    public const string PENDING_DEPARTMENT_DESCRIPTION = 'Temporary grouping for specializations listed on the supplied clinic-schedule poster. This is not an official Queen Mary department name and requires hospital confirmation.';

    /**
     * @return list<array<string, mixed>>
     */
    public static function doctors(): array
    {
        return [
            [
                'key' => 'acosta-emil-rommel',
                'display_name' => 'Dr. Acosta, Emil Rommel',
                'specialization' => 'Otolaryngologist (Ears, Nose, Throat)',
                'room' => '108',
                'booking_mode' => 'fixed',
                'notes' => [],
                'schedules' => [
                    self::window(DayOfWeek::Thursday, '15:00', '16:00'),
                ],
            ],
            [
                'key' => 'arada-hocate-maria-nickle',
                'display_name' => 'Dr. Arada-Hocate, Maria Nickle',
                'specialization' => 'Pediatrician',
                'room' => '100',
                'booking_mode' => 'fixed',
                'notes' => ['Source listed Tuesday and Thursday hours starting 9 Oct 2025. The schedule model has no effective-from date.'],
                'schedules' => [
                    self::window(DayOfWeek::Tuesday, '15:00', '17:00'),
                    self::window(DayOfWeek::Thursday, '14:00', '17:00'),
                ],
            ],
            [
                'key' => 'balajadia-ma-imelda',
                'display_name' => 'Dr. Balajadia, Ma. Imelda',
                'specialization' => 'IM-Cardiologist',
                'room' => '106b',
                'booking_mode' => 'fixed',
                'notes' => [],
                'schedules' => [
                    self::window(DayOfWeek::Monday, '12:00', '15:00'),
                    self::window(DayOfWeek::Friday, '12:00', '15:00'),
                ],
            ],
            [
                'key' => 'cabreira-alvin',
                'display_name' => 'Dr. Cabreira, Alvin',
                'specialization' => 'Urologist',
                'room' => '101',
                'booking_mode' => 'fixed',
                'notes' => [],
                'schedules' => [
                    self::window(DayOfWeek::Thursday, '14:00', '16:00'),
                ],
            ],
            [
                'key' => 'cadiente-gilbert',
                'display_name' => 'Dr. Cadiente, Gilbert',
                'specialization' => 'Internal Medicine',
                'room' => '109',
                'booking_mode' => 'fixed',
                'notes' => [],
                'schedules' => [
                    self::window(DayOfWeek::Monday, '11:00', '13:00'),
                    self::window(DayOfWeek::Wednesday, '11:00', '13:00'),
                    self::window(DayOfWeek::Friday, '11:00', '13:00'),
                ],
            ],
            [
                'key' => 'chulvo-chan-ma-nina-lourdes',
                'display_name' => 'Dr. Chulvo-Chan, Ma. Niña Lourdes',
                'specialization' => 'Internal Medicine',
                'room' => '100',
                'booking_mode' => 'fixed',
                'notes' => ['Source listed Tuesday and Saturday hours starting 18 Oct 2025. The schedule model has no effective-from date.'],
                'schedules' => [
                    self::window(DayOfWeek::Tuesday, '09:00', '12:00'),
                    self::window(DayOfWeek::Saturday, '14:00', '17:00'),
                ],
            ],
            [
                'key' => 'combe-reynaldo',
                'display_name' => 'Dr. Combe, Reynaldo',
                'specialization' => 'Urologist',
                'room' => '103',
                'booking_mode' => 'fixed',
                'notes' => [],
                'schedules' => [
                    self::window(DayOfWeek::Monday, '15:00', '17:00'),
                ],
            ],
            [
                'key' => 'daquigan-jr-angel',
                'display_name' => 'Dr. Daquigan Jr., Angel',
                'specialization' => 'General Surgery',
                'room' => '101',
                'booking_mode' => 'fixed',
                'notes' => [],
                'schedules' => [
                    self::window(DayOfWeek::Thursday, '10:00', '12:00'),
                    self::window(DayOfWeek::Saturday, '10:00', '12:00'),
                ],
            ],
            [
                'key' => 'dela-cruz-michael',
                'display_name' => 'Dr. Dela Cruz, Michael',
                'specialization' => 'IM-Nephrologist',
                'room' => '107',
                'booking_mode' => 'fixed',
                'notes' => [],
                'schedules' => [
                    self::window(DayOfWeek::Wednesday, '13:00', '16:00'),
                    self::window(DayOfWeek::Saturday, '13:00', '16:00'),
                ],
            ],
            [
                'key' => 'de-jesus-mediatrix',
                'display_name' => 'Dr. De Jesus, Mediatrix',
                'specialization' => 'Obstetrician Gynecologist & Integrative',
                'room' => 'OPD',
                'booking_mode' => 'fixed',
                'notes' => [
                    'Source splits Wednesday as OB and Thursday & Friday as Integrative Medicine, with the same 10:00–12:00 hours.',
                    'MediGuide stores one specialization string per doctor, so the combined source label is preserved.',
                ],
                'ambiguous' => true,
                'schedules' => [
                    self::window(DayOfWeek::Wednesday, '10:00', '12:00'),
                    self::window(DayOfWeek::Thursday, '10:00', '12:00'),
                    self::window(DayOfWeek::Friday, '10:00', '12:00'),
                ],
            ],
            [
                'key' => 'dulay-dennis',
                'display_name' => 'Dr. Dulay, Dennis',
                'specialization' => 'IM-Oncologist',
                'room' => '103',
                'booking_mode' => 'fixed',
                'notes' => [],
                'schedules' => [
                    self::window(DayOfWeek::Wednesday, '14:00', '16:00'),
                ],
            ],
            [
                'key' => 'eclipse-jr-eulalio',
                'display_name' => 'Dr. Eclipse Jr., Eulalio',
                'specialization' => 'General Surgery',
                'room' => '107',
                'booking_mode' => 'by_appointment',
                'notes' => ['Source lists Tuesday 10:00–12:00 by appointment. Hours are not imported as ordinary bookable slots until a booking rule is approved.'],
                'ambiguous' => true,
                'listed_hours' => [
                    self::window(DayOfWeek::Tuesday, '10:00', '12:00'),
                ],
                'schedules' => [],
            ],
            [
                'key' => 'endaya-hazel-anne',
                'display_name' => 'Dr. Endaya, Hazel Anne',
                'specialization' => 'Pediatrician',
                'room' => '101',
                'booking_mode' => 'fixed',
                'notes' => [],
                'schedules' => [
                    self::window(DayOfWeek::Saturday, '14:00', '17:00'),
                ],
            ],
            [
                'key' => 'enriquez-tyron-vincent-p',
                'display_name' => 'Dr. Enriquez, Tyron Vincent P.',
                'specialization' => 'Orthopedic Surgeon & Traumatology (Fracture, Bone & Joint Disease)',
                'room' => '106b',
                'booking_mode' => 'by_appointment',
                'notes' => ['Source lists Wednesday and Saturday 13:00–16:00 by appointment. Hours are not imported as ordinary bookable slots until a booking rule is approved.'],
                'ambiguous' => true,
                'listed_hours' => [
                    self::window(DayOfWeek::Wednesday, '13:00', '16:00'),
                    self::window(DayOfWeek::Saturday, '13:00', '16:00'),
                ],
                'schedules' => [],
            ],
            [
                'key' => 'saquitan-epetia-angeli',
                'display_name' => 'Dr. Saquitan-Epetia, Angeli',
                'specialization' => 'Developmental and Behavioral Pediatrician',
                'room' => '105',
                'booking_mode' => 'strictly_by_appointment',
                'notes' => ['Source says Friday, strictly by appointment, with no consultation hours.'],
                'schedules' => [],
            ],
            [
                'key' => 'epetia-mar-christopher',
                'display_name' => 'Dr. Epetia, Mar Christopher',
                'specialization' => 'Pediatric Rheumatologist',
                'room' => '105',
                'booking_mode' => 'strictly_by_appointment',
                'notes' => ['Source says Friday, strictly by appointment, with no consultation hours.'],
                'schedules' => [],
            ],
        ];
    }

    /**
     * @return list<string>
     */
    public static function developmentDepartmentNames(): array
    {
        return [
            'Development Department A',
            'Development Department B',
            'Development Department C',
        ];
    }

    /**
     * @return list<string>
     */
    public static function developmentClinicNames(): array
    {
        return [
            'Development Clinic A',
            'Development Clinic B',
            'Development Clinic C',
        ];
    }

    /**
     * @return list<string>
     */
    public static function developmentDoctorNames(): array
    {
        return [
            'Test Doctor 1',
            'Test Doctor 2',
            'Test Doctor 3',
        ];
    }

    /**
     * @return array{day: DayOfWeek, start: string, end: string}
     */
    private static function window(DayOfWeek $day, string $start, string $end): array
    {
        return [
            'day' => $day,
            'start' => $start,
            'end' => $end,
        ];
    }
}
