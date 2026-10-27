<?php

use App\Exceptions\RecordIntegrityException;
use App\Http\Middleware\EnsureAccountIsActive;
use App\Http\Middleware\EnsureAiDisclaimerAcknowledged;
use App\Http\Middleware\EnsureUserHasRole;
use App\Http\Middleware\HandleAppearance;
use App\Http\Middleware\HandleInertiaRequests;
use App\Support\AuthenticatedHome;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Middleware\AddLinkHeadersForPreloadedAssets;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->encryptCookies(except: ['appearance', 'sidebar_state']);

        $middleware->web(append: [
            EnsureAccountIsActive::class,
            HandleAppearance::class,
            HandleInertiaRequests::class,
            AddLinkHeadersForPreloadedAssets::class,
        ]);

        $middleware->alias([
            'role' => EnsureUserHasRole::class,
            'ai.disclaimer' => EnsureAiDisclaimerAcknowledged::class,
        ]);

        $middleware->redirectUsersTo(fn (Request $request) => AuthenticatedHome::route($request->user()));
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->render(function (RecordIntegrityException $exception, Request $request) {
            if ($request->expectsJson()) {
                return response()->json(['message' => $exception->getMessage()], 409);
            }

            return back()->with('appointment_error', $exception->getMessage());
        });

        // QueryException messages embed bound values (names, contact numbers,
        // birth dates, password hashes); log the statement shape instead.
        $exceptions->report(function (QueryException $exception): false {
            Log::error('Database query failed.', [
                'connection' => $exception->getConnectionName(),
                'sql' => $exception->getSql(),
                'sqlstate' => (string) ($exception->errorInfo[0] ?? $exception->getCode()),
                'driver_code' => $exception->errorInfo[1] ?? null,
                'origin' => collect($exception->getTrace())
                    ->filter(fn (array $frame): bool => isset($frame['file'])
                        && ! str_contains($frame['file'], DIRECTORY_SEPARATOR.'vendor'.DIRECTORY_SEPARATOR))
                    ->take(5)
                    ->map(fn (array $frame): string => $frame['file'].':'.($frame['line'] ?? '?'))
                    ->values()
                    ->all(),
            ]);

            return false;
        });

        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );
    })->create();
