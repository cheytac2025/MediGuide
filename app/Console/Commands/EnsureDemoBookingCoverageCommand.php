<?php

namespace App\Console\Commands;

use App\Services\Demo\DemoBookingCoverageService;
use App\Support\Demo\DemoBookingEnvironment;
use Illuminate\Console\Command;
use RuntimeException;

/**
 * Writes provisional demonstration windows so every eligible active doctor
 * is bookable on an isolated demo database. Never targets production DBs.
 */
class EnsureDemoBookingCoverageCommand extends Command
{
    /**
     * @var string
     */
    protected $signature = 'mediguide:ensure-demo-booking-coverage
        {--confirm-local-demonstration : Required confirmation that this isolated demo database may be changed}
        {--confirm-database= : Exact database name; must match the effective connection}
        {--dry-run : Inventory and propose coverage without writing}';

    /**
     * @var string
     */
    protected $description = 'Ensure every eligible active doctor has a future provisional demonstration booking slot on an isolated demo database';

    public function handle(DemoBookingCoverageService $coverage): int
    {
        $reasons = DemoBookingEnvironment::refusalReasons(
            (bool) $this->option('confirm-local-demonstration'),
            is_string($this->option('confirm-database')) ? $this->option('confirm-database') : null,
        );

        if ($reasons !== []) {
            foreach ($reasons as $reason) {
                $this->error('Refusing: '.$reason);
            }

            return self::FAILURE;
        }

        $dryRun = (bool) $this->option('dry-run');

        try {
            $result = $coverage->ensureCoverage($dryRun);
        } catch (RuntimeException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        $this->info($dryRun
            ? 'Dry-run demo booking coverage inventory (no writes).'
            : 'Demo booking coverage applied on this isolated database only.');

        $this->table(
            ['Metric', 'Value'],
            [
                ['Eligible active doctors', (string) $result['eligible_active']],
                ['Already bookable', (string) $result['already_bookable']],
                ['Made / would make bookable', (string) $result['made_bookable']],
                ['Schedules created', (string) $result['created_schedules']],
                ['Skipped inactive clinic/department', (string) $result['skipped_inactive_scope']],
                ['Still unbookable', (string) count($result['still_unbookable'])],
                ['Bookable after', (string) $result['bookable_after']],
            ],
        );

        $this->line($dryRun ? '=== INVENTORY (current) ===' : '=== INVENTORY (before writes) ===');
        foreach ($result['inventory'] as $row) {
            $this->line(sprintf(
                'DOCTOR id=%d bookable=%s appt_only=%s approved=%d provisional=%d next=%s name=%s spec=%s',
                $row['id'],
                $row['bookable'] ? 'yes' : 'no',
                $row['appointment_only'] ? 'yes' : 'no',
                $row['approved_schedules'],
                $row['provisional_schedules'],
                $row['next'] ?? 'none',
                $row['name'],
                $row['specialization'] ?? 'n/a',
            ));
        }

        if (! $dryRun && $result['created_schedules'] > 0) {
            $this->line('=== POST-COVERAGE SNAPSHOT ===');
            foreach (app(DemoBookingCoverageService::class)->activeDoctors() as $doctor) {
                $row = app(DemoBookingCoverageService::class)->inventoryRow($doctor);
                $this->line(sprintf(
                    'DOCTOR id=%d bookable=%s provisional=%d next=%s name=%s spec=%s',
                    $row['id'],
                    $row['bookable'] ? 'yes' : 'no',
                    $row['provisional_schedules'],
                    $row['next'] ?? 'none',
                    $row['name'],
                    $row['specialization'] ?? 'n/a',
                ));
            }
        }

        if ($result['still_unbookable'] !== []) {
            foreach ($result['still_unbookable'] as $row) {
                $this->warn(sprintf('UNBOOKABLE id=%d reason=%s name=%s', $row['id'], $row['reason'], $row['name']));
            }

            return self::FAILURE;
        }

        if ($result['eligible_active'] > 0 && $result['bookable_after'] !== $result['eligible_active'] && ! $dryRun) {
            $this->error('Coverage incomplete: bookable_after does not equal eligible_active.');

            return self::FAILURE;
        }

        $this->warn('Created windows are provisional demonstration slots, not hospital-verified schedules.');
        $this->warn('Keep HOSPITAL_PROVISIONAL_LOCAL_BOOKING=false on public production.');

        return self::SUCCESS;
    }
}
