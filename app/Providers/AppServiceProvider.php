<?php

namespace App\Providers;

use App\Http\Controllers\Patient\AiGuidanceController;
use App\Listeners\RecordAuthenticationAudit;
use App\Models\Appointment;
use App\Models\Patient;
use App\Observers\RecordIntegrityObserver;
use App\Support\Audit\AuditCheckpointStore;
use App\Support\Audit\FileAuditCheckpointStore;
use App\Support\Audit\InactiveAuditCheckpointStore;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Events\Failed;
use Illuminate\Auth\Events\Login;
use Illuminate\Auth\Events\Logout;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // One instance must see both the updating and updated events.
        $this->app->singleton(RecordIntegrityObserver::class);
        $this->app->singleton(AuditCheckpointStore::class, function (): AuditCheckpointStore {
            $path = config('audit.checkpoint_path');

            if (is_string($path) && $path !== '' && FileAuditCheckpointStore::pathIsOutsideWebRoot($path)) {
                return new FileAuditCheckpointStore($path);
            }

            return new InactiveAuditCheckpointStore;
        });
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->configureDefaults();
        Appointment::observe(RecordIntegrityObserver::class);
        Patient::observe(RecordIntegrityObserver::class);
        Event::listen(Login::class, [RecordAuthenticationAudit::class, 'login']);
        Event::listen(Failed::class, [RecordAuthenticationAudit::class, 'failed']);
        Event::listen(Logout::class, [RecordAuthenticationAudit::class, 'logout']);
    }

    /**
     * Configure default behaviors for production-ready applications.
     */
    protected function configureDefaults(): void
    {
        Date::use(CarbonImmutable::class);

        DB::prohibitDestructiveCommands(
            app()->isProduction(),
        );

        Password::defaults(fn (): ?Password => app()->isProduction()
            ? Password::min(12)
                ->mixedCase()
                ->letters()
                ->numbers()
                ->symbols()
                ->uncompromised()
            : null,
        );

        RateLimiter::for('register', function (Request $request) {
            return Limit::perMinute(5)->by($request->ip());
        });

        RateLimiter::for('ai-guidance', function (Request $request) {
            return Limit::perMinute(6)
                ->by((string) $request->ip())
                ->response(function (Request $request, array $headers) {
                    return response()->json([
                        'type' => 'uncertain',
                        'message' => AiGuidanceController::RATE_LIMIT_MESSAGE,
                    ], 429, $headers);
                });
        });
    }
}
