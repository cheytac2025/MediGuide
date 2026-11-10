<?php

require dirname(__DIR__).'/vendor/autoload.php';

/*
 * PHPUnit applies phpunit.xml environment variables before this file.
 * --no-configuration does not load this file; Tests\TestCase repeats the guard.
 */
App\Support\Testing\TestDatabaseGuard::assertProcessEnvironment();
