<?php

namespace App\Providers;

use App\Events\OrderReadyForPickup;
use App\Events\ShipmentArrivedAtDestination;
use App\Listeners\SendOrderReadyForPickupWebhook;
use App\Listeners\SendShipmentAtDestinationWebhook;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->bind(
            \App\Contracts\Billing\BillingGateway::class,
            \App\Infrastructure\Billing\BillingServiceHttpClient::class,
        );
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        RateLimiter::for('automation', function (Request $request) {
            $limit = max(1, (int) config('services.automation.rate_limit_per_minute', 120));
            $key = $request->ip() ?? 'automation';

            return Limit::perMinute($limit)->by($key);
        });

        Event::listen(
            ShipmentArrivedAtDestination::class,
            SendShipmentAtDestinationWebhook::class,
        );

        Event::listen(
            OrderReadyForPickup::class,
            SendOrderReadyForPickupWebhook::class,
        );
    }
}
