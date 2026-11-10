<?php

namespace App\Support\Scheduling;

use Illuminate\Support\Carbon;

/**
 * Turns consultation windows into non-overlapping bookable slots.
 */
final class ConsultationWindows
{
    /**
     * @param  list<array{start: string, end: string, slot_duration: int}>  $windows
     * @return list<array{start: string, end: string}>
     */
    public static function slots(array $windows): array
    {
        $slots = [];

        foreach ($windows as $window) {
            $duration = ((int) $window['slot_duration']) * 60;

            if ($duration < 1) {
                continue;
            }

            $cursor = self::seconds($window['start']);
            $end = self::seconds($window['end']);

            while ($cursor + $duration <= $end) {
                $slots[] = [
                    'start' => self::his($cursor),
                    'end' => self::his($cursor + $duration),
                ];
                $cursor += $duration;
            }
        }

        return $slots;
    }

    public static function his(int $seconds): string
    {
        $seconds = max(0, $seconds);

        return sprintf(
            '%02d:%02d:%02d',
            intdiv($seconds, 3600),
            intdiv($seconds % 3600, 60),
            $seconds % 60,
        );
    }

    public static function seconds(string $time): int
    {
        $value = trim($time);

        if (preg_match('/^\d{2}:\d{2}$/', $value) === 1) {
            $value .= ':00';
        }

        $parsed = Carbon::createFromFormat('!H:i:s', $value);

        if (! $parsed instanceof Carbon) {
            return 0;
        }

        return ((int) $parsed->format('H') * 3600)
            + ((int) $parsed->format('i') * 60)
            + (int) $parsed->format('s');
    }

    public static function normalize(string $time): string
    {
        return self::his(self::seconds($time));
    }
}
