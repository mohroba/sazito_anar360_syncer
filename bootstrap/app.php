<?php

use App\Console\Commands\RetryFailuresCommand;
use App\Console\Commands\SyncHealthCommand;
use App\Console\Commands\SyncProductsCommand;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return tap(Application::configure(basePath: dirname(__DIR__))
    ->withCommands([
        SyncProductsCommand::class,
        RetryFailuresCommand::class,
        SyncHealthCommand::class,
    ])
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withSchedule(function (Schedule $schedule): void {
        $schedule->command('sync:products', [
            '--since-ms' => config('integrations.anar360.since_ms'),
        ])->everyTenMinutes()->withoutOverlapping()->onOneServer();

        $schedule->command('sync:retry-failures')->everyFiveMinutes()->withoutOverlapping()->onOneServer();
    })
    ->withMiddleware(function (Middleware $middleware): void {
        //
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        //
    })->create(), function (Application $app): void {
        if (($_ENV['APP_ENV'] ?? $_SERVER['APP_ENV'] ?? 'production') === 'testing') {
            $app->loadEnvironmentFrom('.env.testing');
        }
    });
