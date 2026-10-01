<?php

use App\Http\Middleware\EmbeddedSession;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
        then: function (): void {
            Route::group([], base_path('routes/import.php'));
        },
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // Imported rows are archived metadata and must arrive byte for byte:
        // trimming would strip the invisible characters some titles end in.
        $imported = fn (Request $request): bool => $request->is('import/*');

        $middleware->trimStrings(except: [$imported]);
        $middleware->convertEmptyStringsToNull(except: [$imported]);

        // Before the session starts: a framed request gets its own session cookie.
        $middleware->web(prepend: [EmbeddedSession::class]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );
    })->create();
