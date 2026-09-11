<?php

namespace App\Providers;

use App\Services\Parsing\HttpJsonStrategy;
use App\Services\Parsing\RealSleeper;
use App\Services\Parsing\ReviewSourceStrategy;
use App\Services\Parsing\Sleeper;
use App\Services\Parsing\UserAgentPool;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->singleton(UserAgentPool::class, function () {
            return new UserAgentPool(config('yandex_maps.user_agents'));
        });

        $this->app->bind(Sleeper::class, RealSleeper::class);

        $this->app->bind(ReviewSourceStrategy::class, function ($app) {
            return new HttpJsonStrategy(
                baseUrl: config('yandex_maps.base_url'),
                timeout: config('yandex_maps.timeout'),
                pageSize: config('yandex_maps.reviews_page_size'),
                userAgentPool: $app->make(UserAgentPool::class),
                sleeper: $app->make(Sleeper::class),
                throttleMinSeconds: config('yandex_maps.throttle_min_seconds'),
                throttleMaxSeconds: config('yandex_maps.throttle_max_seconds'),
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
