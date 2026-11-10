<?php

namespace Tests\Unit;

use App\Exceptions\UnsafeTestDatabaseException;
use App\Support\Testing\TestDatabaseGuard;
use PHPUnit\Framework\TestCase;

class TestDatabaseGuardTest extends TestCase
{
    /**
     * @var array<string, array{env: mixed, server: mixed, os: string|false}>
     */
    private array $saved = [];

    protected function tearDown(): void
    {
        $this->restoreEnvironment();

        parent::tearDown();
    }

    public function test_explicit_sqlite_memory_configuration_is_accepted(): void
    {
        $this->replaceEnvironment([
            'APP_ENV' => 'testing',
            'DB_CONNECTION' => 'sqlite',
            'DB_DATABASE' => ':memory:',
            'DB_URL' => '',
            'DATABASE_URL' => '',
        ]);

        TestDatabaseGuard::assertProcessEnvironment();

        $this->addToAssertionCount(1);
    }

    public function test_missing_configuration_is_rejected(): void
    {
        $this->replaceEnvironment([
            'APP_ENV' => null,
            'DB_CONNECTION' => null,
            'DB_DATABASE' => null,
            'DB_URL' => null,
            'DATABASE_URL' => null,
        ]);

        $this->expectException(UnsafeTestDatabaseException::class);
        $this->expectExceptionMessage('before a database connection');

        TestDatabaseGuard::assertProcessEnvironment();
    }

    public function test_server_connection_is_rejected_without_using_its_name(): void
    {
        $this->replaceEnvironment([
            'APP_ENV' => 'testing',
            'DB_CONNECTION' => 'mysql',
            'DB_DATABASE' => 'simulated_nonexistent',
            'DB_URL' => '',
            'DATABASE_URL' => '',
        ]);

        $this->expectException(UnsafeTestDatabaseException::class);
        $this->expectExceptionMessage('DB_CONNECTION must be the explicit value sqlite');

        TestDatabaseGuard::assertProcessEnvironment();
    }

    public function test_database_url_is_rejected(): void
    {
        $this->replaceEnvironment([
            'APP_ENV' => 'testing',
            'DB_CONNECTION' => 'sqlite',
            'DB_DATABASE' => ':memory:',
            'DB_URL' => 'sqlite://ignored',
            'DATABASE_URL' => '',
        ]);

        $this->expectException(UnsafeTestDatabaseException::class);
        $this->expectExceptionMessage('DB_URL and DATABASE_URL must be empty');

        TestDatabaseGuard::assertProcessEnvironment();
    }

    public function test_testing_environment_alone_is_rejected(): void
    {
        $this->replaceEnvironment([
            'APP_ENV' => 'testing',
            'DB_CONNECTION' => null,
            'DB_DATABASE' => null,
            'DB_URL' => null,
            'DATABASE_URL' => null,
        ]);

        $this->expectException(UnsafeTestDatabaseException::class);
        $this->expectExceptionMessage('DB_CONNECTION must be the explicit value sqlite');

        TestDatabaseGuard::assertProcessEnvironment();
    }

    /**
     * @param  array<string, string|null>  $values
     */
    private function replaceEnvironment(array $values): void
    {
        $this->restoreEnvironment();

        foreach ($values as $name => $value) {
            $this->saved[$name] = [
                'env' => array_key_exists($name, $_ENV) ? $_ENV[$name] : null,
                'env_set' => array_key_exists($name, $_ENV),
                'server' => array_key_exists($name, $_SERVER) ? $_SERVER[$name] : null,
                'server_set' => array_key_exists($name, $_SERVER),
                'os' => getenv($name),
            ];

            if ($value === null) {
                unset($_ENV[$name], $_SERVER[$name]);
                putenv($name);
            } else {
                $_ENV[$name] = $value;
                $_SERVER[$name] = $value;
                putenv($name.'='.$value);
            }
        }
    }

    private function restoreEnvironment(): void
    {
        foreach ($this->saved as $name => $saved) {
            if ($saved['env_set']) {
                $_ENV[$name] = $saved['env'];
            } else {
                unset($_ENV[$name]);
            }

            if ($saved['server_set']) {
                $_SERVER[$name] = $saved['server'];
            } else {
                unset($_SERVER[$name]);
            }

            if ($saved['os'] === false) {
                putenv($name);
            } else {
                putenv($name.'='.$saved['os']);
            }
        }

        $this->saved = [];
    }
}
