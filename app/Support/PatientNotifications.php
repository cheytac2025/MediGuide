<?php

namespace App\Support;

use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * Ordering, filtering, and date grouping for a patient's notification inbox.
 */
final class PatientNotifications
{
    /**
     * @return MorphMany<DatabaseNotification, User>
     */
    public static function ordered(User $user, string $filter): MorphMany
    {
        $query = $user->notifications()->orderByDesc('created_at')->orderByDesc('id');

        return match ($filter) {
            'unread' => $query->whereNull('read_at'),
            'read' => $query->whereNotNull('read_at'),
            default => $query,
        };
    }

    public static function normalizeFilter(mixed $filter): string
    {
        return is_string($filter) && in_array($filter, ['unread', 'read'], true) ? $filter : 'all';
    }

    /**
     * @param  Collection<int, DatabaseNotification>  $notifications
     * @return list<array{label: string, items: list<DatabaseNotification>}>
     */
    public static function groups(Collection $notifications): array
    {
        $buckets = [
            'Today' => [],
            'Yesterday' => [],
            'Earlier' => [],
        ];

        foreach ($notifications as $notification) {
            $buckets[self::groupLabel($notification)][] = $notification;
        }

        $groups = [];

        foreach ($buckets as $label => $items) {
            if ($items === []) {
                continue;
            }

            $groups[] = [
                'label' => $label,
                'items' => $items,
            ];
        }

        return $groups;
    }

    public static function timeLabel(DatabaseNotification $notification): string
    {
        $created = self::createdAt($notification);

        if (! $created instanceof CarbonInterface) {
            return '';
        }

        $today = now(self::timezone())->startOfDay();
        $day = $created->copy()->startOfDay();

        if ($day->equalTo($today)) {
            return 'Today · '.$created->format('g:i A');
        }

        if ($day->equalTo($today->copy()->subDay())) {
            return 'Yesterday · '.$created->format('g:i A');
        }

        return $created->format('F j, Y');
    }

    public static function iconName(DatabaseNotification $notification): string
    {
        return match ($notification->data['event'] ?? '') {
            'appointment_confirmed' => 'shield-check',
            'appointment_rejected' => 'exclamation-triangle',
            'appointment_cancelled' => 'close',
            'appointment_submitted' => 'clipboard',
            default => 'bell',
        };
    }

    /**
     * @return list<array{title: string, message: string, time: string, unread: bool, url: string}>
     */
    public static function previews(User $user, int $limit = 5): array
    {
        return self::ordered($user, 'all')
            ->limit($limit)
            ->get()
            ->map(fn (DatabaseNotification $notification): array => [
                'title' => (string) ($notification->data['title'] ?? 'Notification'),
                'message' => Str::limit((string) ($notification->data['message'] ?? ''), 90),
                'time' => self::timeLabel($notification),
                'unread' => $notification->unread(),
                'url' => route('patient.notifications.open', $notification),
            ])
            ->all();
    }

    private static function groupLabel(DatabaseNotification $notification): string
    {
        $created = self::createdAt($notification);

        if (! $created instanceof CarbonInterface) {
            return 'Earlier';
        }

        $today = now(self::timezone())->startOfDay();
        $day = $created->copy()->startOfDay();

        if ($day->equalTo($today)) {
            return 'Today';
        }

        if ($day->equalTo($today->copy()->subDay())) {
            return 'Yesterday';
        }

        return 'Earlier';
    }

    private static function createdAt(DatabaseNotification $notification): ?CarbonInterface
    {
        $created = $notification->created_at;

        if (! $created instanceof CarbonInterface) {
            return null;
        }

        return $created->timezone(self::timezone());
    }

    private static function timezone(): string
    {
        $timezone = config('app.timezone');

        return is_string($timezone) && $timezone !== '' ? $timezone : 'Asia/Manila';
    }
}
