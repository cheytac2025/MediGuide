<?php

namespace Tests\Feature\Security;

use App\Enums\Sex;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class SensitiveLoggingTest extends TestCase
{
    use RefreshDatabase;

    /** @var list<string> */
    private array $logged = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RoleSeeder::class);

        config(['logging.default' => 'null']);
        Log::forgetChannel();
        Event::listen(MessageLogged::class, function (MessageLogged $event): void {
            $this->logged[] = $event->level.' '.$event->message.' '.json_encode($event->context, JSON_PARTIAL_OUTPUT_ON_ERROR);
        });
    }

    public function test_a_registration_database_failure_is_logged_without_patient_values(): void
    {
        Schema::table('patients', fn ($table) => $table->dropColumn('contact_number'));

        $this->post(route('register.store'), [
            'first_name' => 'Rosalinda',
            'last_name' => 'Macaraeg',
            'date_of_birth' => '1987-06-21',
            'sex' => Sex::Female->value,
            'contact_number' => '09178889999',
            'email' => 'rosalinda.logging@example.com',
            'password' => 'MediGuide@2026',
            'password_confirmation' => 'MediGuide@2026',
        ])->assertSessionHasErrors('email');

        $this->assertNull(User::query()->where('email', 'rosalinda.logging@example.com')->first());

        $log = implode("\n", $this->logged);

        $this->assertStringContainsString('Database query failed.', $log);
        $this->assertStringContainsString('insert into', $log);
        $this->assertMatchesRegularExpression('/"sqlstate":"\w+"/', $log);

        foreach (['Rosalinda', 'Macaraeg', '09178889999', '1987-06-21', 'rosalinda.logging@example.com', 'MediGuide@2026', '$2y$'] as $sensitive) {
            $this->assertStringNotContainsString($sensitive, $log);
        }
    }

    public function test_unhandled_query_exceptions_keep_operational_details_but_not_bound_values(): void
    {
        try {
            DB::table('missing_table')->where('email', 'hidden.value@example.com')->first();
            $this->fail('Expected a query exception.');
        } catch (QueryException $exception) {
            $this->assertStringContainsString('hidden.value@example.com', $exception->getMessage());

            report($exception);
        }

        $log = implode("\n", $this->logged);

        $this->assertStringContainsString('Database query failed.', $log);
        $this->assertStringContainsString('missing_table', $log);
        $this->assertStringContainsString('SensitiveLoggingTest.php', $log);
        $this->assertStringNotContainsString('hidden.value@example.com', $log);
    }
}
