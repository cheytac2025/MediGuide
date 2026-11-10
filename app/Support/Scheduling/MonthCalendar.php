<?php

namespace App\Support\Scheduling;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

/**
 * Month grid using the same Sunday-start layout as the patient booking calendar.
 */
final class MonthCalendar
{
    /**
     * @param  callable(array{day: int, date: string, label: string, available: bool, selected: bool, today: bool}): array<string, mixed>  $decorate
     * @return array{label: string, prev: string|null, next: string|null, weeks: list<list<array<string, mixed>|null>>}
     */
    public function build(
        CarbonInterface $month,
        ?string $selected,
        ?CarbonInterface $firstMonth,
        ?CarbonInterface $lastMonth,
        callable $decorate,
    ): array {
        $month = CarbonImmutable::parse($month->toDateString())->startOfMonth();
        $today = CarbonImmutable::now(config('app.timezone'))->toDateString();
        $cells = array_fill(0, $month->dayOfWeek, null);

        for ($day = $month; $day->month === $month->month; $day = $day->addDay()) {
            $value = $day->toDateString();

            $cells[] = $decorate([
                'day' => $day->day,
                'date' => $value,
                'label' => $day->format('l, F j, Y'),
                'available' => true,
                'selected' => $value === $selected,
                'today' => $value === $today,
            ]);
        }

        while (count($cells) % 7 !== 0) {
            $cells[] = null;
        }

        $previous = $month->subMonth();
        $following = $month->addMonth();

        return [
            'label' => $month->format('F Y'),
            'prev' => $firstMonth === null || $month->gt($firstMonth->copy()->startOfMonth()) ? $previous->format('Y-m') : null,
            'next' => $lastMonth === null || $month->lt($lastMonth->copy()->startOfMonth()) ? $following->format('Y-m') : null,
            'weeks' => array_chunk($cells, 7),
        ];
    }
}
