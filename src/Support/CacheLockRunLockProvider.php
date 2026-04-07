<?php

declare(strict_types=1);

namespace Entrepeneur4lyf\LaravelConductor\Support;

use Closure;
use Entrepeneur4lyf\LaravelConductor\Contracts\RunLockProvider;
use Entrepeneur4lyf\LaravelConductor\Exceptions\RunLockedException;
use Illuminate\Contracts\Cache\Factory as CacheFactory;
use Illuminate\Contracts\Cache\LockProvider;
use Illuminate\Contracts\Cache\LockTimeoutException;
use RuntimeException;

final class CacheLockRunLockProvider implements RunLockProvider
{
    public function __construct(
        private readonly CacheFactory $cache,
    ) {
    }

    public function withLock(string $runId, Closure $callback, int $blockSeconds = 5): mixed
    {
        $store = config('conductor.locks.store');
        $ttl = (int) config('conductor.locks.ttl', 30);
        $prefix = (string) config('conductor.locks.prefix', 'conductor:run:');

        $repository = is_string($store) && $store !== ''
            ? $this->cache->store($store)
            : $this->cache->store();

        $lockProvider = $this->resolveLockProvider($repository);

        if ($lockProvider === null) {
            throw new RuntimeException(sprintf(
                'Configured conductor lock store [%s] does not support atomic locks.',
                is_string($store) && $store !== '' ? $store : 'default',
            ));
        }

        try {
            return $lockProvider
                ->lock($prefix.$runId, $ttl)
                ->block($blockSeconds, $callback);
        } catch (LockTimeoutException $exception) {
            throw new RunLockedException($runId, $exception);
        }
    }

    private function resolveLockProvider(mixed $repository): ?LockProvider
    {
        if ($repository instanceof LockProvider) {
            return $repository;
        }

        // The default Illuminate cache repository proxies lock() through to
        // its underlying store via __call. Reach for that store directly so
        // type checks (and PHPStan) can see the LockProvider contract.
        if (is_object($repository) && method_exists($repository, 'getStore')) {
            $store = $repository->getStore();

            if ($store instanceof LockProvider) {
                return $store;
            }
        }

        return null;
    }
}
