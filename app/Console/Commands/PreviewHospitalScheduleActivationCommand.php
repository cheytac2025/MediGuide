<?php

namespace App\Console\Commands;

use App\Services\HospitalImport\HospitalScheduleActivationPreview;
use App\Support\HospitalImport\QueenMaryClinicScheduleCatalog;
use Illuminate\Console\Command;
use JsonException;

/**
 * Prints a schedule-activation preview. This command has no execute path.
 */
class PreviewHospitalScheduleActivationCommand extends Command
{
    /**
     * @var string
     */
    protected $signature = 'mediguide:preview-schedule-activation
        {--hypothetical-minutes= : Display-only duration. It is not approved and is not saved.}
        {--json : Print the preview as JSON}';

    /**
     * @var string
     */
    protected $description = 'Preview hospital poster hours for online booking without writing schedules or appointments';

    public function handle(HospitalScheduleActivationPreview $preview): int
    {
        $minutes = $this->option('hypothetical-minutes');
        $durations = [];

        if (is_numeric($minutes) && (int) $minutes > 0) {
            foreach (QueenMaryClinicScheduleCatalog::doctors() as $doctor) {
                if (($doctor['booking_mode'] ?? null) === 'fixed' && isset($doctor['key'])) {
                    $durations[(string) $doctor['key']] = (int) $minutes;
                }
            }

            $this->warn('Hypothetical duration is not hospital-approved and was not saved.');
        }

        $summary = $preview->preview([
            'approved_durations' => $durations,
        ]);

        if ($this->option('json')) {
            try {
                $this->line(json_encode($summary, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
            } catch (JsonException) {
                $this->error('The activation preview could not be encoded.');

                return self::FAILURE;
            }

            return self::SUCCESS;
        }

        $this->info('DRY RUN — no database writes.');
        $this->line($summary['source']);
        $this->warn($summary['source_note']);
        $this->line('Verification: '.$summary['verification']);
        $this->line('Timezone: '.$summary['timezone']);
        $this->line('Booking window days: '.$summary['booking_window_days']);
        $this->line('Rows: '.count($summary['rows']));
        $this->line('Would become online-bookable: '.$summary['online_bookable_count']);

        return self::SUCCESS;
    }
}
