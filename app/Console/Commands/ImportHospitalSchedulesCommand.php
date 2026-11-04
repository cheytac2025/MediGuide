<?php

namespace App\Console\Commands;

use App\Services\HospitalImport\HospitalScheduleImportService;
use Illuminate\Console\Command;
use JsonException;

/**
 * Dry-run hospital schedule import. Writes nothing unless --execute is passed.
 */
class ImportHospitalSchedulesCommand extends Command
{
    /**
     * @var string
     */
    protected $signature = 'mediguide:import-hospital-schedules
        {--execute : Write records. Dry-run is the default.}
        {--deactivate-development : Deactivate unused development placeholders}
        {--bookable-slot-minutes= : Approved slot length for fixed-hour schedules. Omit to skip bookable rows.}
        {--json : Print the preview as JSON}';

    /**
     * @var string
     */
    protected $description = 'Preview or import Queen Mary clinic-schedule poster records without secretary numbers or login accounts';

    public function handle(HospitalScheduleImportService $import): int
    {
        $execute = (bool) $this->option('execute');
        $deactivate = (bool) $this->option('deactivate-development');
        $minutes = $this->option('bookable-slot-minutes');
        $slotMinutes = is_numeric($minutes) ? (int) $minutes : null;

        if ($execute && app()->isProduction() && ! $this->confirm('Execute the hospital schedule import in production?')) {
            $this->warn('Import cancelled.');

            return self::SUCCESS;
        }

        $summary = $execute
            ? $import->execute($slotMinutes, $deactivate)
            : $import->preview($slotMinutes, $deactivate);

        if ($this->option('json')) {
            try {
                $this->line(json_encode($summary, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
            } catch (JsonException) {
                $this->error('The import preview could not be encoded.');

                return self::FAILURE;
            }

            return self::SUCCESS;
        }

        $this->info($summary['dry_run'] ? 'DRY RUN — no database writes.' : 'EXECUTE — records were written.');
        $this->line($summary['source']);
        $this->warn($summary['source_note']);
        $this->line('Verification: '.$summary['verification']);
        $this->newLine();
        $this->line('Departments: '.count($summary['departments']));
        $this->line('Clinics: '.count($summary['clinics']));
        $this->line('Doctors: '.count($summary['doctors']));
        $this->line('Fixed-hour schedule rows: '.count($summary['schedules']));
        $this->line('Appointment-only doctors: '.count($summary['appointment_only']));
        $this->line('Ambiguous entries: '.count($summary['ambiguous']));
        $this->line('Duplicates skipped: '.count($summary['duplicates']));
        $this->line('Skipped incomplete rows: '.count($summary['skipped']));
        $this->newLine();
        $this->line('Created: '.json_encode($summary['created']));
        $this->line('Deactivated: '.json_encode($summary['deactivated']));

        if ($summary['appointment_only'] !== []) {
            $this->newLine();
            $this->warn('Appointment-only restrictions were not imported as bookable slots.');
        }

        if ($slotMinutes === null) {
            $this->comment('No --bookable-slot-minutes value was provided. Fixed hours are listed in the preview only.');
        }

        return self::SUCCESS;
    }
}
