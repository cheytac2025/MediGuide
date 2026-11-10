<?php

namespace App\Console\Commands;

use App\Models\Appointment;
use App\Models\Doctor;
use App\Models\DoctorSchedule;
use App\Services\HospitalImport\HospitalScheduleImportService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Writes provisional 30-minute poster schedules on the local demonstration database only.
 */
class ActivateProvisionalSchedulesCommand extends Command
{
    /**
     * @var string
     */
    protected $signature = 'mediguide:activate-provisional-schedules
        {--confirm-local-demonstration : Required confirmation that this local demonstration database may be changed}
        {--confirm-database= : Exact database name; must match the effective connection}';

    /**
     * @var string
     */
    protected $description = 'Activate provisional 30-minute poster schedules on the local demonstration database';

    public function handle(HospitalScheduleImportService $import): int
    {
        if (! $this->option('confirm-local-demonstration')) {
            $this->error('Refusing to write. Re-run with --confirm-local-demonstration after a verified local backup.');

            return self::FAILURE;
        }

        if (! $this->isLocalLoopbackMysql()) {
            $this->error('Refusing to write. Provisional schedules can be activated only on local MySQL at 127.0.0.1.');

            return self::FAILURE;
        }

        $confirmed = $this->option('confirm-database');
        $effective = (string) config('database.connections.mysql.database');

        if (! is_string($confirmed) || $confirmed === '' || $confirmed !== $effective) {
            $this->error('Refusing to write. --confirm-database must exactly match the effective database connection.');

            return self::FAILURE;
        }

        if (config('hospital.provisional_local_booking') !== true) {
            $this->error('Refusing to write. HOSPITAL_PROVISIONAL_LOCAL_BOOKING must be true on this local demo and must stay false everywhere else.');

            return self::FAILURE;
        }

        $before = $this->counts();

        try {
            $summary = DB::transaction(function () use ($import, $before): array {
                $summary = $import->execute(30, false);
                $after = $this->counts();

                if ($after['doctors'] !== $before['doctors'] || $after['appointments'] !== $before['appointments']) {
                    throw new RuntimeException('Activation would change doctors or appointments.');
                }

                $importedSchedules = DoctorSchedule::query()
                    ->where('provisional_demonstration', true)
                    ->where('slot_duration', 30)
                    ->count();

                if ($importedSchedules !== 21) {
                    throw new RuntimeException('Expected 21 provisional 30-minute windows.');
                }

                $appointmentOnly = Doctor::query()
                    ->whereIn('display_name', [
                        'Dr. Eclipse Jr., Eulalio',
                        'Dr. Enriquez, Tyron Vincent P.',
                        'Dr. Saquitan-Epetia, Angeli',
                        'Dr. Epetia, Mar Christopher',
                    ])
                    ->withCount('schedules')
                    ->get();

                if ($appointmentOnly->count() !== 4 || $appointmentOnly->sum('schedules_count') !== 0) {
                    throw new RuntimeException('Appointment-only doctors must remain without public schedules.');
                }

                return $summary;
            });
        } catch (RuntimeException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        $this->info('Provisional demonstration schedules are active on this local database only.');
        $this->line('Schedules created this run: '.$summary['created']['schedules']);
        $this->line('Doctors created this run: '.$summary['created']['doctors']);
        $this->warn('These rows are not hospital-verified. They stay hidden unless this local flag remains enabled.');

        return self::SUCCESS;
    }

    /**
     * Environment, driver, and host checks. The database name is confirmed separately.
     */
    private function isLocalLoopbackMysql(): bool
    {
        if (! app()->environment('local')) {
            return false;
        }

        $connection = (string) config('database.default');

        if ($connection !== 'mysql') {
            return false;
        }

        $host = (string) config('database.connections.mysql.host');

        return in_array($host, ['127.0.0.1', 'localhost', '::1'], true);
    }

    /**
     * @return array{doctors: int, appointments: int}
     */
    private function counts(): array
    {
        return [
            'doctors' => Doctor::query()->count(),
            'appointments' => Appointment::query()->count(),
        ];
    }
}
