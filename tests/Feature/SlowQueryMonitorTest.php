<?php

declare(strict_types=1);

use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Xybr\QueryMonitor\SlowQueryDetectedException;
use Xybr\QueryMonitor\SlowQueryFingerprint;
use Xybr\QueryMonitor\SlowQueryMonitor;

beforeEach(function () {
    app('events')->forget(QueryExecuted::class);

    Schema::create('users', function ($table) {
        $table->id();
        $table->string('name');
    });

    Schema::create('orders', function ($table) {
        $table->id();
        $table->string('status');
    });
});

afterEach(function () {
    Schema::dropIfExists('users');
    Schema::dropIfExists('orders');
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
                && is_float($e->duration)
                && is_string($e->fingerprint)
                && $e->fingerprint !== '';
        });
    });

    it('groups identical query patterns into a single exception with occurrence count', function () {
        Exceptions::fake();

        config()->set('query-monitor.threshold_ms', 0);

        $monitor = new SlowQueryMonitor;
        $monitor->boot();

        DB::select('select * from "users" where "id" = 1');
        DB::select('select * from "users" where "id" = 42');

        $monitor->reportCollectedQueries();

        expect(Exceptions::reported())->toHaveCount(1);

        Exceptions::assertReported(function (SlowQueryDetectedException $e) {
            return $e->occurrences === 2
                && $e->table === 'users'
                && str_contains($e->fingerprint, 'users');
        });
    });

    it('reports distinct query patterns as separate exceptions', function () {
        Exceptions::fake();

        config()->set('query-monitor.threshold_ms', 0);

        $monitor = new SlowQueryMonitor;
        $monitor->boot();

        DB::select('select * from "users" where "id" = 1');
        DB::select('select * from "orders" where "status" = ?', ['pending']);

        $monitor->reportCollectedQueries();

        expect(Exceptions::reported())->toHaveCount(2);
    });

    it('sets the exception code to the fingerprint hash', function () {
        Exceptions::fake();

        config()->set('query-monitor.threshold_ms', 0);

        $monitor = new SlowQueryMonitor;
        $monitor->boot();

        DB::select('select * from "users" where "id" = 1');

        $monitor->reportCollectedQueries();

        Exceptions::assertReported(function (SlowQueryDetectedException $e) {
            $expectedCode = crc32(
                (new SlowQueryFingerprint('select * from "users" where "id" = 1'))->normalized
            );

            return $e->getCode() === $expectedCode;
        });
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

    it('handles slow queries without crashing when Nightwatch is absent', function () {
        $monitor = Mockery::mock(SlowQueryMonitor::class)
            ->makePartial()
            ->shouldAllowMockingProtectedMethods()
            ->shouldReceive('nightwatchAvailable')
            ->andReturn(false)
            ->getMock();

        Exceptions::fake();

        config()->set('query-monitor.threshold_ms', 0);

        $monitor->boot();

        DB::select('select * from "users" where "id" = 1');
        DB::select('select * from "users" where "id" = 42');

        $monitor->reportCollectedQueries();

        Exceptions::assertReported(SlowQueryDetectedException::class);
        expect(Exceptions::reported())->toHaveCount(1);
    });

    it('reports the max duration from grouped queries', function () {
        Exceptions::fake();

        config()->set('query-monitor.threshold_ms', 0);

        $monitor = new SlowQueryMonitor;
        $monitor->boot();

        DB::select('select * from "users" where "id" = 1');

        $monitor->reportCollectedQueries();

        Exceptions::assertReported(function (SlowQueryDetectedException $e) {
            return $e->duration >= 0.0;
        });
    });

    it('extracts table name from from clause', function () {
        $fp = new SlowQueryFingerprint('select * from "users" where "active" = 1');

        expect($fp->table)->toBe('users');
    });

    it('extracts table name from update clause', function () {
        $fp = new SlowQueryFingerprint('update "accounts" set "balance" = 0');

        expect($fp->table)->toBe('accounts');
    });

    it('extracts table name from insert into clause', function () {
        $fp = new SlowQueryFingerprint('insert into "logs" ("message") values (?)');

        expect($fp->table)->toBe('logs');
    });

    it('returns null table for queries without table references', function () {
        $fp = new SlowQueryFingerprint('select 1 as value');

        expect($fp->table)->toBeNull();
    });

    it('normalizes different literal values to the same fingerprint', function () {
        $fp1 = new SlowQueryFingerprint('select * from "users" where "id" = 1');
        $fp2 = new SlowQueryFingerprint('select * from "users" where "id" = 42');

        expect($fp1->normalized)->toBe($fp2->normalized);
        expect($fp1->hash)->toBe($fp2->hash);
    });

    it('produces different fingerprints for different table references', function () {
        $fp1 = new SlowQueryFingerprint('select * from "users" where "id" = 1');
        $fp2 = new SlowQueryFingerprint('select * from "orders" where "id" = 1');

        expect($fp1->normalized)->not->toBe($fp2->normalized);
        expect($fp1->hash)->not->toBe($fp2->hash);
    });

    it('normalizes single-quoted strings as placeholders', function () {
        $fp = new SlowQueryFingerprint("select * from \"users\" where \"name\" = 'John'");

        expect($fp->normalized)->toContain('?');
        expect($fp->normalized)->not->toContain('John');
    });

    it('normalizes case differences to the same fingerprint', function () {
        $fp1 = new SlowQueryFingerprint('SELECT * FROM "users"');
        $fp2 = new SlowQueryFingerprint('select * from "users"');

        expect($fp1->normalized)->toBe($fp2->normalized);
    });

    it('sets the exception message correctly with table name', function () {
        $e = new SlowQueryDetectedException(
            sql: 'select * from "users" where "id" = 1',
            duration: 250.0,
            connection: 'mysql',
            fingerprint: (new SlowQueryFingerprint('select * from "users" where "id" = 1'))->normalized,
            table: 'users',
        );

        expect($e->getMessage())->toContain('on "users"');
        expect($e->getMessage())->toContain('250ms');
        expect($e->getMessage())->toContain('mysql');
    });

    it('sets the exception message with occurrence count', function () {
        $e = new SlowQueryDetectedException(
            sql: 'select * from "users" where "id" = 1',
            duration: 250.0,
            connection: 'mysql',
            fingerprint: (new SlowQueryFingerprint('select * from "users" where "id" = 1'))->normalized,
            table: 'users',
            occurrences: 5,
        );

        expect($e->getMessage())->toContain('250ms × 5');
    });

    it('auto-computes fingerprint and table when not provided', function () {
        $e = new SlowQueryDetectedException(
            sql: 'select * from "users" where "id" = 1',
            duration: 100.0,
            connection: 'mysql',
        );

        expect($e->fingerprint)->toBeString();
        expect($e->table)->toBe('users');
        expect($e->getCode())->toBeInt();
    });
});

describe('SlowQueryFingerprint', function () {
    it('normalizes whitespace', function () {
        $fp = new SlowQueryFingerprint('select   *    from "users"');

        expect($fp->normalized)->toBe('select * from "users"');
    });

    it('handles backtick-quoted identifiers', function () {
        $fp = new SlowQueryFingerprint('select * from `users` where `id` = 1');

        expect($fp->table)->toBe('users');
    });

    it('handles bracket-quoted identifiers', function () {
        $fp = new SlowQueryFingerprint('select * from [users] where [id] = 1');

        expect($fp->table)->toBe('users');
    });
});
