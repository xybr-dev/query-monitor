<?php

declare(strict_types=1);

namespace Xybr\QueryMonitor;

use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;
use Throwable;

use function Illuminate\Support\defer;

class SlowQueryMonitor
{
    private array $slowQueries = [];

    private bool $sampled = false;

    public function boot(): void
    {
        DB::listen(function (QueryExecuted $query): void {
            $threshold = (float) config('query-monitor.threshold_ms', 150);

            if ($query->time < $threshold) {
                return;
            }

            if ($this->shouldIgnore($query->sql)) {
                return;
            }

            $this->slowQueries[] = $query;

            if (! $this->sampled) {
                $this->forceSampleRequest();
            }
        });

        defer(function (): void {
            $this->reportCollectedQueries();
        }, always: true);
    }

    public function reportCollectedQueries(): void
    {
        $queries = $this->slowQueries;
        $this->slowQueries = [];
        $this->sampled = false;

        foreach ($queries as $query) {
            report(new SlowQueryDetectedException(
                sql: $query->sql,
                duration: $query->time,
                connection: $query->connectionName,
            ));
        }
    }

    private function shouldIgnore(string $sql): bool
    {
        $patterns = (array) config('query-monitor.ignore', []);

        foreach ($patterns as $pattern) {
            if (str_contains($sql, $pattern)) {
                return true;
            }
        }

        return false;
    }

    private function forceSampleRequest(): void
    {
        $this->sampled = true;

        if (! $this->nightwatchAvailable()) {
            return;
        }

        try {
            \Laravel\Nightwatch\Facades\Nightwatch::sample();
        } catch (Throwable) {
            //
        }
    }

    protected function nightwatchAvailable(): bool
    {
        return class_exists(\Laravel\Nightwatch\Facades\Nightwatch::class);
    }
}
