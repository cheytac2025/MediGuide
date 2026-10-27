<?php

/**
 * Proves InnoDB locking for integrity seals on an isolated database.
 *
 * This script refuses the live mediguide database and any restore database.
 * It creates mediguide_integrity_concurrency, runs the checks, then drops it.
 */

use App\Enums\AppointmentStatus;
use App\Enums\RoleName;
use App\Enums\Sex;
use App\Exceptions\RecordIntegrityException;
use App\Models\Appointment;
use App\Models\Doctor;
use App\Models\DoctorSchedule;
use App\Models\User;
use App\Services\AppointmentBookingService;
use App\Support\Integrity\IntegrityStatus;
use App\Support\Integrity\RecordIntegrityService;
use Database\Seeders\DevelopmentClinicSeeder;
use Database\Seeders\DevelopmentDepartmentSeeder;
use Database\Seeders\DevelopmentDoctorScheduleSeeder;
use Database\Seeders\DevelopmentDoctorSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

const INTEGRITY_TEST_DATABASE = 'mediguide_integrity_concurrency';

$root = dirname(__DIR__);
require $root.'/vendor/autoload.php';
$app = require $root.'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

if (isset($argv[1]) && $argv[1] !== '') {
    exit(runWorker($argv));
}

exit(runParent());

function runParent(): int
{
    $connection = config('database.connections.mysql');
    $host = (string) ($connection['host'] ?? '');

    if (! in_array($host, ['127.0.0.1', 'localhost'], true)) {
        fwrite(STDERR, "Refused: MySQL host is not local.\n");

        return 1;
    }

    $live = (string) ($connection['database'] ?? '');

    if ($live !== 'mediguide' || str_contains($live, 'restore') || $live === INTEGRITY_TEST_DATABASE) {
        fwrite(STDERR, "Refused: unexpected configured database.\n");

        return 1;
    }

    $pdo = serverPdo($connection);
    $pdo->exec('DROP DATABASE IF EXISTS `'.INTEGRITY_TEST_DATABASE.'`');
    $pdo->exec('CREATE DATABASE `'.INTEGRITY_TEST_DATABASE.'` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
    $results = [];

    try {
        useTestDatabase();
        Artisan::call('migrate', ['--force' => true, '--database' => 'mysql']);
        assertEngine();

        foreach ([
            RoleSeeder::class,
            DevelopmentDepartmentSeeder::class,
            DevelopmentClinicSeeder::class,
            DevelopmentDoctorSeeder::class,
            DevelopmentDoctorScheduleSeeder::class,
        ] as $seeder) {
            Artisan::call('db:seed', ['--class' => $seeder, '--force' => true]);
        }

        $results = [
            staleOverwrite(),
            atomicRollback(),
            lockTimeout(),
            deadlock(),
            bookingRace(),
        ];
    } catch (Throwable $exception) {
        fwrite(STDERR, 'Isolated concurrency check failed: '.$exception::class."\n");
        $results[] = false;
    } finally {
        DB::disconnect('mysql');
        DB::disconnect('mysql_peer');
        $pdo->exec('DROP DATABASE IF EXISTS `'.INTEGRITY_TEST_DATABASE.'`');
        $left = $pdo->query("SHOW DATABASES LIKE '".INTEGRITY_TEST_DATABASE."'")->fetch();
        clearFlags();

        if ($left !== false) {
            fwrite(STDERR, "The isolated test database was not removed.\n");
            $results[] = false;
        }
    }

    $passed = ! in_array(false, $results, true);
    fwrite(STDOUT, $passed ? "MYSQL_CONCURRENCY_PASS\n" : "MYSQL_CONCURRENCY_FAIL\n");

    return $passed ? 0 : 1;
}

/**
 * @param  array<string, mixed>  $connection
 */
function serverPdo(array $connection): PDO
{
    return new PDO(
        sprintf('mysql:host=%s;port=%s', $connection['host'], $connection['port'] ?? 3306),
        (string) $connection['username'],
        (string) ($connection['password'] ?? ''),
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION],
    );
}

function useTestDatabase(): void
{
    config(['database.connections.mysql.database' => INTEGRITY_TEST_DATABASE]);
    DB::purge('mysql');
    DB::reconnect('mysql');
    $current = (string) DB::select('select database() as name')[0]->name;

    if ($current !== INTEGRITY_TEST_DATABASE) {
        throw new RuntimeException('Connected to the wrong database.');
    }
}

function assertEngine(): void
{
    $engine = DB::selectOne(
        'select ENGINE as engine from information_schema.TABLES where TABLE_SCHEMA = ? and TABLE_NAME = ?',
        [INTEGRITY_TEST_DATABASE, 'appointments'],
    );

    if (($engine->engine ?? '') !== 'InnoDB') {
        throw new RuntimeException('Appointments are not InnoDB.');
    }
}

function staleOverwrite(): bool
{
    $appointment = makeAppointment('stale');
    $holderProcess = startWorker('stale-holder', [$appointment->id]);
    $racerProcess = startWorker('stale-racer', [$appointment->id]);
    $holder = finishWorker($holderProcess);
    $racer = finishWorker($racerProcess);
    $status = (string) DB::table('appointments')->where('id', $appointment->id)->value('status');
    $valid = app(RecordIntegrityService::class)->statusFor('appointment', $appointment->id) === IntegrityStatus::Valid;
    $ok = ($holder['result'] ?? '') === 'committed'
        && ($racer['result'] ?? '') === 'stale'
        && $status === AppointmentStatus::Confirmed->value
        && $valid;
    report('stale overwrite', $ok, $holder, $racer, $status);

    return $ok;
}

function atomicRollback(): bool
{
    $appointment = makeAppointment('atomic');
    $id = $appointment->id;
    $originalStatus = (string) DB::table('appointments')->where('id', $id)->value('status');
    $originalHmac = (string) DB::table('record_integrity')->where('subject_type', 'appointment')->where('subject_id', $id)->value('hmac');
    config(['database.connections.mysql_peer' => config('database.connections.mysql')]);
    DB::purge('mysql_peer');
    DB::connection('mysql')->beginTransaction();
    DB::connection('mysql')->table('appointments')->where('id', $id)->update(['status' => AppointmentStatus::Confirmed->value]);
    DB::connection('mysql')->table('record_integrity')->where('subject_type', 'appointment')->where('subject_id', $id)->update(['hmac' => str_repeat('cd', 32)]);
    $peerStatus = (string) DB::connection('mysql_peer')->table('appointments')->where('id', $id)->value('status');
    $peerHmac = (string) DB::connection('mysql_peer')->table('record_integrity')->where('subject_type', 'appointment')->where('subject_id', $id)->value('hmac');
    DB::connection('mysql')->rollBack();
    $afterStatus = (string) DB::connection('mysql_peer')->table('appointments')->where('id', $id)->value('status');
    $afterHmac = (string) DB::connection('mysql_peer')->table('record_integrity')->where('subject_type', 'appointment')->where('subject_id', $id)->value('hmac');
    $valid = app(RecordIntegrityService::class)->statusFor('appointment', $id) === IntegrityStatus::Valid;
    $ok = $peerStatus === $originalStatus
        && $peerHmac === $originalHmac
        && $afterStatus === $originalStatus
        && $afterHmac === $originalHmac
        && $valid;
    report('atomic rollback', $ok, ['result' => $peerStatus], ['result' => $afterStatus], $afterStatus);

    return $ok;
}

function lockTimeout(): bool
{
    $appointment = makeAppointment('timeout');
    $holderProcess = startWorker('timeout-holder', [$appointment->id]);
    $racerProcess = startWorker('timeout-racer', [$appointment->id]);
    $holder = finishWorker($holderProcess);
    $racer = finishWorker($racerProcess);
    $status = (string) DB::table('appointments')->where('id', $appointment->id)->value('status');
    $valid = app(RecordIntegrityService::class)->statusFor('appointment', $appointment->id) === IntegrityStatus::Valid;
    $ok = ($holder['result'] ?? '') === 'rolled-back'
        && ($racer['result'] ?? '') === 'lock-timeout'
        && $status === AppointmentStatus::Pending->value
        && $valid;
    report('lock timeout', $ok, $holder, $racer, $status);

    return $ok;
}

function deadlock(): bool
{
    $appointment = makeAppointment('deadlock');
    $firstProcess = startWorker('deadlock-a', [$appointment->id]);
    $secondProcess = startWorker('deadlock-b', [$appointment->id]);
    $first = finishWorker($firstProcess);
    $second = finishWorker($secondProcess);
    $status = (string) DB::table('appointments')->where('id', $appointment->id)->value('status');
    $valid = app(RecordIntegrityService::class)->statusFor('appointment', $appointment->id) === IntegrityStatus::Valid;
    $codes = [($first['result'] ?? ''), ($second['result'] ?? '')];
    $ok = in_array('deadlock', $codes, true)
        && $status === AppointmentStatus::Pending->value
        && $valid;
    report('deadlock', $ok, $first, $second, $status);

    return $ok;
}

function bookingRace(): bool
{
    Carbon::setTestNow('2026-10-05 08:00:00');
    $first = makePatient('book-a');
    $second = makePatient('book-b');
    $leftProcess = startWorker('book', [$first->patient->id]);
    $rightProcess = startWorker('book', [$second->patient->id]);
    $left = finishWorker($leftProcess);
    $right = finishWorker($rightProcess);
    $doctor = Doctor::query()->where('display_name', 'Test Doctor 1')->firstOrFail();
    $count = Appointment::query()
        ->where('doctor_id', $doctor->id)
        ->whereDate('appointment_date', '2026-10-12')
        ->where('start_time', '09:00:00')
        ->count();
    $row = Appointment::query()
        ->where('doctor_id', $doctor->id)
        ->whereDate('appointment_date', '2026-10-12')
        ->where('start_time', '09:00:00')
        ->first();
    $valid = $row !== null && app(RecordIntegrityService::class)->statusFor('appointment', $row->id) === IntegrityStatus::Valid;
    $outcomes = [($left['result'] ?? ''), ($right['result'] ?? '')];
    sort($outcomes);
    $ok = $outcomes === ['booked', 'overlap'] && $count === 1 && $valid;
    report('booking race', $ok, $left, $right, (string) $count);

    return $ok;
}

function makeAppointment(string $label): Appointment
{
    $user = makePatient($label);
    $doctor = Doctor::query()->where('display_name', 'Test Doctor 1')->firstOrFail();
    $schedule = DoctorSchedule::query()->where('doctor_id', $doctor->id)->orderBy('id')->firstOrFail();

    return Appointment::query()->create([
        'patient_id' => $user->patient->id,
        'doctor_id' => $doctor->id,
        'doctor_schedule_id' => $schedule->id,
        'appointment_date' => '2026-10-12',
        'start_time' => '11:00:00',
        'end_time' => '11:30:00',
        'patient_concern' => null,
        'status' => AppointmentStatus::Pending,
    ]);
}

function makePatient(string $label): User
{
    $user = User::factory()->role(RoleName::Patient)->create([
        'email' => $label.'-'.bin2hex(random_bytes(4)).'@example.test',
    ]);
    $user->patient()->create([
        'date_of_birth' => '1990-01-15',
        'sex' => Sex::Female,
        'contact_number' => '0917000'.random_int(1000, 9999),
    ]);

    return $user->fresh(['patient']);
}

/**
 * @param  list<int>  $arguments
 * @return array{process: resource|false, pipes: array<int, resource>}
 */
function startWorker(string $role, array $arguments): array
{
    $command = array_merge([PHP_BINARY, __FILE__, $role], array_map('strval', $arguments));
    $pipes = [];
    $process = proc_open($command, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, dirname(__DIR__));

    return ['process' => $process, 'pipes' => $pipes];
}

/**
 * @param  array{process: resource|false, pipes: array<int, resource>}  $worker
 * @return array<string, string>
 */
function finishWorker(array $worker): array
{
    if (! is_resource($worker['process'])) {
        return ['result' => 'not-started'];
    }

    $output = stream_get_contents($worker['pipes'][1]);
    fclose($worker['pipes'][1]);
    fclose($worker['pipes'][2]);
    proc_close($worker['process']);
    $decoded = json_decode((string) $output, true);

    if (! is_array($decoded)) {
        return ['result' => 'unreadable'];
    }

    return array_map(strval(...), $decoded);
}

/**
 * @param  array<string, string>  $left
 * @param  array<string, string>  $right
 */
function report(string $name, bool $ok, array $left, array $right, string $status): void
{
    $detail = $ok ? '' : ' left='.($left['result'] ?? '').' right='.($right['result'] ?? '').' status='.$status;
    fwrite(STDOUT, ($ok ? 'PASS ' : 'FAIL ').$name.$detail."\n");
}

/**
 * @param  list<string>  $argv
 */
function runWorker(array $argv): int
{
    useTestDatabase();
    $role = $argv[1];
    $id = (int) ($argv[2] ?? 0);

    $result = match ($role) {
        'stale-holder' => holdThenUpdate($id),
        'stale-racer' => raceUpdate($id),
        'timeout-holder' => holdThenRollback($id),
        'timeout-racer' => waitThenTimeout($id),
        'deadlock-a' => lockAppointmentThenSeal($id),
        'deadlock-b' => lockSealThenAppointment($id),
        'book' => bookSlot($id),
        default => 'unknown-role',
    };

    fwrite(STDOUT, json_encode(['result' => $result], JSON_THROW_ON_ERROR));

    return 0;
}

function holdThenUpdate(int $id): string
{
    $appointment = Appointment::query()->findOrFail($id);
    DB::beginTransaction();
    lockRecord($id);
    signal($id, 'ready');
    sleep(4);
    $appointment->update(['status' => AppointmentStatus::Confirmed]);
    DB::commit();

    return 'committed';
}

function raceUpdate(int $id): string
{
    waitFor($id, 'ready');
    $appointment = Appointment::query()->findOrFail($id);

    try {
        $appointment->update(['status' => AppointmentStatus::Rejected]);
    } catch (RecordIntegrityException) {
        return 'stale';
    }

    return 'updated';
}

function holdThenRollback(int $id): string
{
    DB::beginTransaction();
    lockRecord($id);
    signal($id, 'ready');
    sleep(6);
    DB::rollBack();

    return 'rolled-back';
}

function waitThenTimeout(int $id): string
{
    waitFor($id, 'ready');
    DB::statement('SET innodb_lock_wait_timeout = 2');
    $appointment = Appointment::query()->findOrFail($id);

    try {
        $appointment->update(['status' => AppointmentStatus::Rejected]);
    } catch (QueryException $exception) {
        return (int) ($exception->errorInfo[1] ?? 0) === 1205 ? 'lock-timeout' : 'query-error';
    }

    return 'updated';
}

function lockAppointmentThenSeal(int $id): string
{
    try {
        DB::beginTransaction();
        DB::table('appointments')->where('id', $id)->lockForUpdate()->first();
        signal($id, 'ready');
        sleep(2);
        DB::table('record_integrity')->where('subject_type', 'appointment')->where('subject_id', $id)->lockForUpdate()->first();
        DB::rollBack();

        return 'locked';
    } catch (QueryException $exception) {
        safeRollBack();

        return (int) ($exception->errorInfo[1] ?? 0) === 1213 ? 'deadlock' : 'query-error';
    }
}

function lockSealThenAppointment(int $id): string
{
    try {
        waitFor($id, 'ready');
        DB::beginTransaction();
        DB::table('record_integrity')->where('subject_type', 'appointment')->where('subject_id', $id)->lockForUpdate()->first();
        DB::table('appointments')->where('id', $id)->lockForUpdate()->first();
        DB::rollBack();

        return 'locked';
    } catch (QueryException $exception) {
        safeRollBack();

        return (int) ($exception->errorInfo[1] ?? 0) === 1213 ? 'deadlock' : 'query-error';
    }
}

function safeRollBack(): void
{
    try {
        DB::rollBack();
    } catch (Throwable) {
    }
}

function bookSlot(int $patientId): string
{
    Carbon::setTestNow('2026-10-05 08:00:00');
    $patient = User::query()->findOrFail(
        DB::table('patients')->where('id', $patientId)->value('user_id'),
    )->patient;
    $doctor = Doctor::query()->where('display_name', 'Test Doctor 1')->firstOrFail();
    $schedule = DoctorSchedule::query()->where('doctor_id', $doctor->id)->where('start_time', '09:00:00')->firstOrFail();

    try {
        app(AppointmentBookingService::class)->book($patient, $doctor, $schedule, '2026-10-12', '09:00');
    } catch (ValidationException) {
        return 'overlap';
    }

    return 'booked';
}

function lockRecord(int $id): void
{
    DB::table('appointments')->where('id', $id)->lockForUpdate()->first();
    DB::table('integrity_subjects')->where('subject_type', 'appointment')->where('subject_id', $id)->lockForUpdate()->first();
    DB::table('record_integrity')->where('subject_type', 'appointment')->where('subject_id', $id)->lockForUpdate()->first();
}

function signal(int $id, string $name): void
{
    file_put_contents(flagPath($id, $name), '1');
}

function waitFor(int $id, string $name): void
{
    $path = flagPath($id, $name);
    $deadline = microtime(true) + 15;

    while (! is_file($path)) {
        if (microtime(true) > $deadline) {
            throw new RuntimeException('Timed out waiting for the other connection.');
        }

        usleep(50000);
    }
}

function flagPath(int $id, string $name): string
{
    $directory = flagDirectory();

    if (! is_dir($directory)) {
        mkdir($directory, 0777, true);
    }

    return $directory.DIRECTORY_SEPARATOR.$name.'-'.$id;
}

function clearFlags(): void
{
    $directory = flagDirectory();

    if (! is_dir($directory)) {
        return;
    }

    foreach (glob($directory.DIRECTORY_SEPARATOR.'*') ?: [] as $file) {
        if (is_file($file)) {
            unlink($file);
        }
    }

    rmdir($directory);
}

function flagDirectory(): string
{
    return dirname(__DIR__).DIRECTORY_SEPARATOR.'storage'.DIRECTORY_SEPARATOR.'framework'.DIRECTORY_SEPARATOR.'integrity-concurrency';
}
