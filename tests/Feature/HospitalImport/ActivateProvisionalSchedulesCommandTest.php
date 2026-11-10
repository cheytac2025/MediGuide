<?php

namespace Tests\Feature\HospitalImport;

use Tests\TestCase;

class ActivateProvisionalSchedulesCommandTest extends TestCase
{
    public function test_activation_requires_the_local_demonstration_confirmation(): void
    {
        $this->artisan('mediguide:activate-provisional-schedules')
            ->expectsOutputToContain('--confirm-local-demonstration')
            ->assertFailed();
    }

    public function test_activation_refuses_a_database_name_that_does_not_match_the_connection(): void
    {
        $this->pretendLocalMysql('simulated_nonexistent');

        $this->artisan('mediguide:activate-provisional-schedules', [
            '--confirm-local-demonstration' => true,
            '--confirm-database' => 'mediguide',
        ])
            ->expectsOutputToContain('--confirm-database must exactly match the effective database connection.')
            ->assertFailed();
    }

    public function test_activation_refuses_the_historical_database_name_unless_it_is_the_effective_connection(): void
    {
        $this->pretendLocalMysql('mediguide');

        $this->artisan('mediguide:activate-provisional-schedules', [
            '--confirm-local-demonstration' => true,
        ])
            ->expectsOutputToContain('--confirm-database must exactly match the effective database connection.')
            ->assertFailed();
    }

    public function test_activation_stops_before_a_query_when_the_local_booking_flag_is_off(): void
    {
        $this->pretendLocalMysql('simulated_nonexistent', provisional: false);

        $this->artisan('mediguide:activate-provisional-schedules', [
            '--confirm-local-demonstration' => true,
            '--confirm-database' => 'simulated_nonexistent',
        ])
            ->expectsOutputToContain('HOSPITAL_PROVISIONAL_LOCAL_BOOKING must be true')
            ->assertFailed();
    }

    public function test_activation_keeps_the_local_mysql_host_guard(): void
    {
        $this->pretendLocalMysql('simulated_nonexistent');
        config(['database.connections.mysql.host' => 'invalid.test']);

        $this->artisan('mediguide:activate-provisional-schedules', [
            '--confirm-local-demonstration' => true,
            '--confirm-database' => 'simulated_nonexistent',
        ])
            ->expectsOutputToContain('only on local MySQL at 127.0.0.1')
            ->assertFailed();
    }

    private function pretendLocalMysql(string $database, bool $provisional = true): void
    {
        $this->app['env'] = 'local';

        config([
            'database.default' => 'mysql',
            'database.connections.mysql.host' => '127.0.0.1',
            'database.connections.mysql.database' => $database,
            'hospital.provisional_local_booking' => $provisional,
        ]);
    }
}
