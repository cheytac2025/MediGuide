<?php

namespace App\Support\Demo;

/**
 * Guards that keep demonstration schedule writes off production databases.
 */
final class DemoBookingEnvironment
{
    /**
     * Production and shared live database names that must never receive
     * synthetic demonstration schedules from this tooling.
     *
     * @var list<string>
     */
    public const BLOCKED_DATABASE_NAMES = [
        'mediguide_demo',
        'mediguide_production',
        'mediguide_live',
    ];

    public static function provisionalBookingConfigured(): bool
    {
        return config('hospital.provisional_local_booking') === true;
    }

    public static function isIsolatedProcess(): bool
    {
        if (! self::provisionalBookingConfigured()) {
            return false;
        }

        $connection = (string) config('database.default');

        if ($connection === 'sqlite') {
            return app()->environment('testing', 'local');
        }

        $host = (string) config("database.connections.{$connection}.host");

        return app()->environment('local')
            && in_array($host, ['127.0.0.1', 'localhost', '::1'], true);
    }

    public static function effectiveDatabaseName(): string
    {
        $connection = (string) config('database.default');

        if ($connection === 'sqlite') {
            return (string) config('database.connections.sqlite.database');
        }

        return (string) config("database.connections.{$connection}.database");
    }

    public static function isBlockedDatabase(?string $database = null): bool
    {
        $name = mb_strtolower(trim((string) ($database ?? self::effectiveDatabaseName())));

        if ($name === '' || $name === ':memory:') {
            return false;
        }

        foreach (self::BLOCKED_DATABASE_NAMES as $blocked) {
            if ($name === mb_strtolower($blocked)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return list<string>
     */
    public static function refusalReasons(
        bool $confirmedDemonstration,
        ?string $confirmedDatabase,
    ): array {
        $reasons = [];

        if (! $confirmedDemonstration) {
            $reasons[] = 'Re-run with --confirm-local-demonstration after a verified isolated backup.';
        }

        if (! self::provisionalBookingConfigured()) {
            $reasons[] = 'HOSPITAL_PROVISIONAL_LOCAL_BOOKING must be true on this isolated demo process only.';
        }

        if (! self::isIsolatedProcess()) {
            $reasons[] = 'Demonstration schedules can be written only on local loopback MySQL or testing SQLite.';
        }

        $effective = self::effectiveDatabaseName();

        if (! is_string($confirmedDatabase) || $confirmedDatabase === '' || $confirmedDatabase !== $effective) {
            $reasons[] = '--confirm-database must exactly match the effective database connection ('.$effective.').';
        }

        if (self::isBlockedDatabase($effective) || self::isBlockedDatabase($confirmedDatabase)) {
            $reasons[] = 'Refusing blocked production/shared database name.';
        }

        return $reasons;
    }
}
