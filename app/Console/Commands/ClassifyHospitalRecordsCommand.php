<?php

namespace App\Console\Commands;

use App\Services\HospitalImport\HospitalRecordClassificationService;
use Illuminate\Console\Command;
use JsonException;

/**
 * Dry-run catalog provenance classification. Writes nothing unless --execute.
 */
class ClassifyHospitalRecordsCommand extends Command
{
    /**
     * @var string
     */
    protected $signature = 'mediguide:classify-hospital-records
        {--execute : Write provenance. Dry-run is the default.}
        {--json : Print the preview as JSON}';

    /**
     * @var string
     */
    protected $description = 'Classify reviewed Queen Mary catalog records as imported without promoting them to verified';

    public function handle(HospitalRecordClassificationService $classification): int
    {
        $execute = (bool) $this->option('execute');

        if ($execute && app()->isProduction() && ! $this->confirm('Classify hospital records in production?')) {
            $this->warn('Classification cancelled.');

            return self::SUCCESS;
        }

        $summary = $execute
            ? $classification->execute()
            : $classification->preview();

        if ($this->option('json')) {
            try {
                $this->line(json_encode($summary, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
            } catch (JsonException) {
                $this->error('The classification preview could not be encoded.');

                return self::FAILURE;
            }

            return self::SUCCESS;
        }

        $this->info($summary['dry_run'] ? 'DRY RUN — no provenance writes.' : 'EXECUTE — provenance was written.');
        $this->line('Target: '.$summary['target']);
        $this->warn('Verification: '.$summary['verification']);
        $this->line('Records reviewed: '.count($summary['records']));
        $this->line('Updated: '.$summary['updated']);

        return self::SUCCESS;
    }
}
