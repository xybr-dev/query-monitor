<?php

declare(strict_types=1);

namespace Xybr\QueryMonitor;

use Illuminate\Support\ServiceProvider;

class QueryMonitorServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(
            __DIR__.'/../config/query-monitor.php',
            'query-monitor',
        );
    }

    public function boot(): void
    {
        if (! config('query-monitor.enabled', true)) {
            return;
        }

        $this->app->make(SlowQueryMonitor::class)->boot();

        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__.'/../config/query-monitor.php' => config_path('query-monitor.php'),
            ], 'query-monitor-config');
        }
    }
}
