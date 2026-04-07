<?php

declare(strict_types=1);

use Entrepeneur4lyf\LaravelConductor\Exceptions\RunLockedException;
use Entrepeneur4lyf\LaravelConductor\Support\CacheLockRunLockProvider;
use Illuminate\Contracts\Cache\Factory as CacheFactory;
use Illuminate\Contracts\Cache\Lock;
use Illuminate\Contracts\Cache\LockProvider;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Support\Facades\Cache;

beforeEach(function (): void {
    config()->set('cache.default', 'array');
    config()->set('conductor.locks.store', 'array');
    config()->set('conductor.locks.prefix', 'conductor:run:');
    config()->set('conductor.locks.ttl', 30);
});

it('acquires and releases a lock around the callback', function (): void {
    $provider = new CacheLockRunLockProvider(app(CacheFactory::class));

    $provider->withLock('run-acquire-release', function (): void {
        // While the callback is executing a second acquisition against the
        // same key should fail because the lock is currently held.
        $other = Cache::store('array')->lock('conductor:run:run-acquire-release', 30);
        expect($other->get())->toBeFalse();
    });

    // Once the callback returns the lock should be released and the key
    // should be acquirable again.
    $after = Cache::store('array')->lock('conductor:run:run-acquire-release', 30);
    expect($after->get())->toBeTrue();
    $after->release();
});

it('returns the callback result', function (): void {
    $provider = new CacheLockRunLockProvider(app(CacheFactory::class));

    $result = $provider->withLock('run-result', fn (): string => 'payload');

    expect($result)->toBe('payload');
});

it('propagates exceptions from the callback while still releasing the lock', function (): void {
    $provider = new CacheLockRunLockProvider(app(CacheFactory::class));

    expect(fn () => $provider->withLock('run-boom', function (): void {
        throw new RuntimeException('boom');
    }))->toThrow(RuntimeException::class, 'boom');

    // The lock must have been released so a subsequent acquire succeeds.
    $after = Cache::store('array')->lock('conductor:run:run-boom', 30);
    expect($after->get())->toBeTrue();
    $after->release();
});

it('throws RunLockedException when the lock cannot be acquired in time', function (): void {
    // Hold the lock through a different owner so the provider cannot acquire.
    $held = Cache::store('array')->lock('conductor:run:run-timeout', 30);
    expect($held->get())->toBeTrue();

    $provider = new CacheLockRunLockProvider(app(CacheFactory::class));

    try {
        expect(fn () => $provider->withLock(
            'run-timeout',
            fn () => 'never',
            0,
        ))->toThrow(RunLockedException::class);
    } finally {
        $held->release();
    }
});

it('uses the configured store, prefix, and ttl', function (): void {
    config()->set('conductor.locks.store', 'custom-store');
    config()->set('conductor.locks.prefix', 'custom:lock:');
    config()->set('conductor.locks.ttl', 7);

    $lock = Mockery::mock(Lock::class);
    $lock->shouldReceive('block')
        ->once()
        ->withArgs(function (int $seconds, Closure $callback): bool {
            return $seconds === 5 && $callback() === 'configured';
        })
        ->andReturnUsing(fn (int $seconds, Closure $callback) => $callback());

    $repository = Mockery::mock(CacheRepository::class, LockProvider::class);
    $repository->shouldReceive('lock')
        ->once()
        ->with('custom:lock:run-config', 7)
        ->andReturn($lock);

    $factory = Mockery::mock(CacheFactory::class);
    $factory->shouldReceive('store')
        ->once()
        ->with('custom-store')
        ->andReturn($repository);

    $provider = new CacheLockRunLockProvider($factory);

    $result = $provider->withLock('run-config', fn (): string => 'configured');

    expect($result)->toBe('configured');
});

it('falls back to the default store when conductor.locks.store is null', function (): void {
    config()->set('conductor.locks.store', null);

    $lock = Mockery::mock(Lock::class);
    $lock->shouldReceive('block')
        ->once()
        ->andReturnUsing(fn (int $seconds, Closure $callback) => $callback());

    $repository = Mockery::mock(CacheRepository::class, LockProvider::class);
    $repository->shouldReceive('lock')
        ->once()
        ->andReturn($lock);

    $factory = Mockery::mock(CacheFactory::class);
    $factory->shouldReceive('store')
        ->once()
        ->withNoArgs()
        ->andReturn($repository);

    $provider = new CacheLockRunLockProvider($factory);

    $result = $provider->withLock('run-default-store', fn (): string => 'ok');

    expect($result)->toBe('ok');
});

it('maps LockTimeoutException to RunLockedException', function (): void {
    $lock = Mockery::mock(Lock::class);
    $lock->shouldReceive('block')
        ->once()
        ->andThrow(new LockTimeoutException());

    $repository = Mockery::mock(CacheRepository::class, LockProvider::class);
    $repository->shouldReceive('lock')
        ->once()
        ->andReturn($lock);

    $factory = Mockery::mock(CacheFactory::class);
    $factory->shouldReceive('store')
        ->once()
        ->andReturn($repository);

    $provider = new CacheLockRunLockProvider($factory);

    expect(fn () => $provider->withLock('run-timeout-mapped', fn () => null))
        ->toThrow(RunLockedException::class);
});
