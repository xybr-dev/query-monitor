<?php

declare(strict_types=1);

use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Route;
use Xybr\QueryMonitor\SlowQueryDetectedException;
use Xybr\QueryMonitor\SlowQueryMonitor;

beforeEach(function () {
    app('events')->forget(QueryExecuted::class);
});

describe('SlowQueryMonitor', function () {
    it('reports slow queries via the exception facade', function () {
        Exceptions::fake();

        config()->set('query-monitor.threshold_ms', 0);

        $monitor = new SlowQueryMonitor;
        $monitor->boot();

        DB::select('select 1 as value');

        $monitor->reportCollectedQueries();

        Exceptions::assertReported(SlowQueryDetectedException::class);
    });

    it('ignores queries under the threshold', function () {
        Exceptions::fake();

        config()->set('query-monitor.threshold_ms', 999999);

        $monitor = new SlowQueryMonitor;
        $monitor->boot();

        DB::select('select 1 as value');

        $monitor->reportCollectedQueries();

        Exceptions::assertNotReported(SlowQueryDetectedException::class);
    });

    it('populates exception properties from the query', function () {
        Exceptions::fake();

        config()->set('query-monitor.threshold_ms', 0);

        $monitor = new SlowQueryMonitor;
        $monitor->boot();

        DB::select('select 1 as value');

        $monitor->reportCollectedQueries();

        Exceptions::assertReported(function (SlowQueryDetectedException $e) {
            return str_contains($e->sql, 'select')
                && is_float($e->duration);
        });
    });

    it('reports each distinct slow query occurrence', function () {
        Exceptions::fake();

        config()->set('query-monitor.threshold_ms', 0);

        $monitor = new SlowQueryMonitor;
        $monitor->boot();

        DB::select('select 1 as value');
        DB::select('select 2 as value');

        $monitor->reportCollectedQueries();

        expect(Exceptions::reported())->toHaveCount(2);
    });

    it('reports slow queries via defer after the response is sent', function () {
        Route::get('/_test-defer-slow-query', function () {
            DB::select('select 1 as value');
        });

        Exceptions::fake();

        config()->set('query-monitor.threshold_ms', 0);

        $monitor = new SlowQueryMonitor;
        $monitor->boot();

        $this->get('/_test-defer-slow-query');

        Exceptions::assertReported(SlowQueryDetectedException::class);
    });

    it('respects the ignore list', function () {
        Exceptions::fake();

        config()->set('query-monitor.threshold_ms', 0);
        config()->set('query-monitor.ignore', ['select 1']);

        $monitor = new SlowQueryMonitor;
        $monitor->boot();

        DB::select('select 1 as value');

        $monitor->reportCollectedQueries();

        Exceptions::assertNotReported(SlowQueryDetectedException::class);
    });
});
