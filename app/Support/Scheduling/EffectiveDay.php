<?php

namespace App\Support\Scheduling;

/**
 * The hours that actually apply to one hospital calendar date.
 */
final class EffectiveDay
{
    public const string RECURRING = 'recurring';

    public const string OVERRIDE = 'override';

    public const string UNAVAILABLE = 'unavailable';

    /**
     * @param  list<array{start: string, end: string, slot_duration: int, provisional: bool, schedule_id: ?int}>  $windows
     */
    public function __construct(
        public string $source,
        public ?int $anchorScheduleId,
        public array $windows,
    ) {}

    /**
     * @return list<array{start: string, end: string, schedule_id: ?int, provisional: bool}>
     */
    public function slots(): array
    {
        $slots = [];

        foreach (ConsultationWindows::slots($this->windows) as $slot) {
            $window = $this->windowForSlot($slot['start']);

            $slots[] = [
                'start' => $slot['start'],
                'end' => $slot['end'],
                'schedule_id' => $window['schedule_id'] ?? null,
                'provisional' => (bool) ($window['provisional'] ?? false),
            ];
        }

        return $slots;
    }

    /**
     * @return array{start: string, end: string, slot_duration: int, provisional: bool, schedule_id: ?int}
     */
    private function windowForSlot(string $start): array
    {
        foreach ($this->windows as $window) {
            if ($start >= $window['start'] && $start < $window['end']) {
                return $window;
            }
        }

        return [
            'start' => '00:00:00',
            'end' => '00:00:00',
            'slot_duration' => 0,
            'provisional' => false,
            'schedule_id' => null,
        ];
    }
}
