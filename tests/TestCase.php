<?php

declare(strict_types=1);

namespace Tests;

use Orchestra\Testbench\TestCase as Orchestra;
use Xybr\QueryMonitor\QueryMonitorServiceProvider;

class TestCase extends Orchestra
{
    protected function getPackageProviders($app): array
    {
        return [
            QueryMonitorServiceProvider::class,
        ];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('query-monitor.enabled', false);
        $app['config']->set('query-monitor.threshold_ms', 150);
        $app['config']->set('query-monitor.ignore', []);
    }
}
