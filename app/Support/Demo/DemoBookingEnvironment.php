<?php

namespace App\Support\Demo;

use App\Models\User;

/**
 * Guards demonstration schedule writes and provisional booking exposure.
 *
 * Supports:
 * - Local loopback / testing with HOSPITAL_PROVISIONAL_LOCAL_BOOKING
 * - Isolated MEDIGUIDE_DEMO_INSTANCE deployments
 * - Capstone defense on the existing public site: provisional slots may be
 *   visible when MEDIGUIDE_CAPSTONE_BOOKING=true, but only allowlisted
 *   synthetic accounts may confirm those bookings.
 */
final class DemoBookingEnvironment
{
    /**
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

    public static function capstoneBookingEnabled(): bool
    {
        return config('hospital.capstone_booking') === true
            && self::capstoneAccounts() !== [];
    }

    /**
     * @return list<string>
     */
    public static function capstoneAccounts(): array
    {
        /** @var list<string> $accounts */
        $accounts = config('hospital.capstone_booking_accounts', []);

        return array_values(array_filter($accounts, static fn ($email): bool => is_string($email) && $email !== ''));
    }

    public static function isCapstoneAccount(?User $user): bool
    {
        if (! $user instanceof User) {
            return false;
        }

        $email = mb_strtolower(trim((string) $user->email));

        if ($email === '') {
            return false;
        }

        return in_array($email, self::capstoneAccounts(), true);
    }

    /**
     * Online / VPS demonstration instance: explicit flag + exact DB allowlist match.
     * Never true for blocked production database names.
     */
    public static function isAuthorizedOnlineDemoInstance(): bool
    {
        if (config('hospital.demo_instance') !== true) {
            return false;
        }

        if (! self::provisionalBookingConfigured()) {
            return false;
        }

        if (self::isBlockedDatabase()) {
            return false;
        }

        $expected = trim((string) config('hospital.demo_database'));

        if ($expected === '' || self::isBlockedDatabase($expected)) {
            return false;
        }

        return $expected === self::effectiveDatabaseName();
    }

    /**
     * Whether provisional demonstration slots may be listed / computed.
     * Capstone mode makes slots visible without enabling global provisional booking.
     */
    public static function provisionalSlotsVisible(): bool
    {
        if (self::provisionalBookingAllowed()) {
            return true;
        }

        return self::capstoneBookingEnabled();
    }

    /**
     * Whether the given user may confirm a provisional demonstration booking.
     */
    public static function provisionalBookingPermitted(?User $user): bool
    {
        if (self::provisionalBookingAllowed()) {
            return true;
        }

        return self::capstoneBookingEnabled() && self::isCapstoneAccount($user);
    }

    /**
     * Unrestricted provisional booking (local/testing or isolated demo instance).
     * Never true solely because capstone account allowlisting is enabled.
     */
    public static function provisionalBookingAllowed(): bool
    {
        if (! self::provisionalBookingConfigured()) {
            return false;
        }

        if (self::isAuthorizedOnlineDemoInstance()) {
            return true;
        }

        $connection = (string) config('database.default');

        if ($connection === 'sqlite') {
            return app()->environment('testing', 'local');
        }

        $host = (string) config("database.connections.{$connection}.host");

        return app()->environment('local')
            && in_array($host, ['127.0.0.1', 'localhost', '::1'], true);
    }

    public static function isIsolatedProcess(): bool
    {
        if (! self::provisionalBookingConfigured()) {
            return false;
        }

        if (self::isAuthorizedOnlineDemoInstance()) {
            return true;
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

    public static function bannerMessage(): string
    {
        return (string) config(
            'hospital.demo_banner',
            'CAPSTONE DEMONSTRATION — schedules and appointments are synthetic and not real hospital bookings.',
        );
    }

    public static function shouldShowBanner(?User $user = null): bool
    {
        if (self::isAuthorizedOnlineDemoInstance()) {
            return true;
        }

        if (self::provisionalBookingAllowed() && app()->environment('local', 'testing') && ! self::isBlockedDatabase()) {
            return true;
        }

        return self::capstoneBookingEnabled()
            && ($user === null || self::isCapstoneAccount($user) || ! auth()->check());
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
            $reasons[] = 'Demonstration schedules can be written only on local loopback MySQL, testing SQLite, or an authorized MEDIGUIDE_DEMO_INSTANCE.';
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
