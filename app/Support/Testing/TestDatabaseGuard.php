<?php

namespace App\Support\Testing;

use App\Exceptions\UnsafeTestDatabaseException;
use Illuminate\Database\DatabaseManager;
use Illuminate\Foundation\Application;

/**
 * Confines the standard PHPUnit suite to an explicit SQLite :memory: database.
 *
 * This inspects the process environment and the resolved configuration. It does
 * not read .env, rewrite settings, or open a connection.
 */
final class TestDatabaseGuard
{
    /**
     * Drivers that can reach a server database. They are refused before PDO.
     *
     * @var list<string>
     */
    private const BLOCKED_DRIVERS = ['mysql', 'mariadb', 'pgsql', 'sqlsrv'];

    /**
     * Stop before Laravel loads configuration or connects.
     */
    public static function assertProcessEnvironment(): void
    {
        if (self::effective('APP_ENV') !== 'testing') {
            throw UnsafeTestDatabaseException::unsafe('APP_ENV must be the explicit value testing.');
        }

        if (self::effective('DB_CONNECTION') !== 'sqlite') {
            throw UnsafeTestDatabaseException::unsafe('DB_CONNECTION must be the explicit value sqlite.');
        }

        if (self::effective('DB_DATABASE') !== ':memory:') {
            throw UnsafeTestDatabaseException::unsafe('DB_DATABASE must be the explicit value :memory:.');
        }

        if (self::presentUrl('DB_URL') || self::presentUrl('DATABASE_URL')) {
            throw UnsafeTestDatabaseException::unsafe('DB_URL and DATABASE_URL must be empty.');
        }
    }

    /**
     * Stop after configuration is resolved and before a connection is made.
     */
    public static function assertConfiguredApplication(Application $app): void
    {
        $default = $app->make('config')->get('database.default');
        $connection = is_string($default)
            ? $app->make('config')->get('database.connections.'.$default)
            : null;

        if ($default !== 'sqlite' || ! is_array($connection) || ($connection['driver'] ?? null) !== 'sqlite') {
            throw UnsafeTestDatabaseException::unsafe('The resolved test connection must be sqlite.');
        }

        if (($connection['database'] ?? null) !== ':memory:') {
            throw UnsafeTestDatabaseException::unsafe('The resolved test database must be :memory:.');
        }

        if (filled($connection['url'] ?? null)) {
            throw UnsafeTestDatabaseException::unsafe('The resolved test connection must not use a database URL.');
        }
    }

    /**
     * Refuse server drivers before their connectors create PDO.
     */
    public static function blockServerDrivers(Application $app): void
    {
        $database = $app->make('db');

        if (! $database instanceof DatabaseManager) {
            throw UnsafeTestDatabaseException::unsafe('The database manager is not available.');
        }

        foreach (self::BLOCKED_DRIVERS as $driver) {
            $database->extend($driver, static function () use ($driver): never {
                throw UnsafeTestDatabaseException::unsafe(
                    "The {$driver} driver is not available to the standard test suite."
                );
            });
        }
    }

    private static function presentUrl(string $name): bool
    {
        $value = self::effective($name);

        return $value !== null && $value !== '';
    }

    /**
     * Laravel reads $_ENV before getenv. PHPUnit's force flag updates both.
     * A disagreement between those two is rejected. $_SERVER is used only when
     * neither of the others is set, because PHPUnit does not clear it.
     */
    private static function effective(string $name): ?string
    {
        $fromEnv = array_key_exists($name, $_ENV) ? self::stringValue($_ENV[$name]) : null;
        $fromOs = getenv($name);
        $fromOs = $fromOs === false ? null : $fromOs;

        if ($fromEnv !== null && $fromOs !== null && $fromEnv !== $fromOs) {
            throw UnsafeTestDatabaseException::unsafe("The environment value for {$name} is inconsistent.");
        }

        if ($fromEnv !== null || $fromOs !== null) {
            return $fromEnv ?? $fromOs;
        }

        if (array_key_exists($name, $_SERVER)) {
            return self::stringValue($_SERVER[$name]);
        }

        return null;
    }

    private static function stringValue(mixed $value): ?string
    {
        if ($value === null || is_array($value) || is_object($value)) {
            return null;
        }

        return (string) $value;
    }
}
