<?php

namespace App\Providers;

use App\Actions\Sync\BackoffPolicy;
use App\Actions\Sync\RecordExternalRequestAction;
use App\Services\Anar360\Anar360Client;
use App\Services\Http\HttpClientFactory;
use App\Services\Sazito\SazitoClient;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->singleton(BackoffPolicy::class, function (): BackoffPolicy {
            $base = (int) config('integrations.http.retry_backoff_ms', 500);

            return new BackoffPolicy($base);
        });

        $this->app->singleton(HttpClientFactory::class, function ($app): HttpClientFactory {
            return new HttpClientFactory(
                $app->make(RecordExternalRequestAction::class),
                $app->make(BackoffPolicy::class),
                (int) config('integrations.http.timeout', 15),
                (int) config('integrations.http.retries', 3),
            );
        });

        $this->app->singleton(Anar360Client::class, function ($app): Anar360Client {
            return new Anar360Client(
                $app->make(HttpClientFactory::class),
                $app->make('validator'),
                config('integrations.anar360'),
            );
        });

        $this->app->singleton(SazitoClient::class, function ($app): SazitoClient {
            return new SazitoClient(
                $app->make(HttpClientFactory::class),
                config('integrations.sazito'),
            );
        });
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        //
    }
}
