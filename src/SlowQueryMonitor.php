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

        $groups = [];

        foreach ($queries as $query) {
            $fp = new SlowQueryFingerprint($query->sql);
            $key = $fp->normalized;

            if (! isset($groups[$key])) {
                $groups[$key] = [
                    'queries' => [],
                    'maxDuration' => 0.0,
                    'fingerprint' => $fp,
                ];
            }

            $groups[$key]['queries'][] = $query;
            $groups[$key]['maxDuration'] = max($groups[$key]['maxDuration'], $query->time);
        }

        foreach ($groups as $group) {
            $fp = $group['fingerprint'];
            $groupQueries = $group['queries'];
            $lastQuery = end($groupQueries);

            report(new SlowQueryDetectedException(
                sql: $lastQuery->sql,
                duration: $group['maxDuration'],
                connection: $lastQuery->connectionName,
                fingerprint: $fp->normalized,
                table: $fp->table,
                occurrences: count($groupQueries),
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
