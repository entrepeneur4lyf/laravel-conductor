<?php

declare(strict_types=1);

use Illuminate\Support\Env;

return [
    'definitions' => [
        'paths' => [
            Env::get('CONDUCTOR_DEFINITIONS_PATH', base_path('workflows')),
        ],
    ],
    'state' => [
        'driver' => Env::get('CONDUCTOR_STATE_DRIVER', 'database'),
    ],
    'escalation' => [
        'agent' => Env::get('CONDUCTOR_ESCALATION_AGENT', 'conductor-supervisor'),
    ],
    'routes' => [
        'prefix' => Env::get('CONDUCTOR_ROUTE_PREFIX', 'api/conductor'),
        'middleware' => [
            'api',
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Run Locks
    |--------------------------------------------------------------------------
    |
    | Conductor wraps every concurrent run mutation (continue, resume, retry,
    | cancel) in a Laravel cache lock so that two parallel HTTP requests
    | cannot drive the same workflow run into a race condition. The lock is
    | acquired via the configured cache store and keyed by the run id with
    | the prefix below.
    |
    | - "store"  selects the cache store used to persist the lock. Leave it
    |            null to use the default cache store. Production deployments
    |            should point this at a shared backend (redis, memcached,
    |            database) so locks are visible across workers.
    | - "prefix" namespaces the lock key so it does not collide with other
    |            cache entries.
    | - "ttl"    is the maximum number of seconds the lock may be held before
    |            it is auto-released by the cache backend.
    |
    | The cache lock is a cheap first-line defense against obvious concurrent
    | requests, not a correctness guarantee. Correctness is provided by
    | optimistic concurrency at the write layer (revision checks in
    | OptimisticRunMutator) plus a pre-Atlas revision re-check inside
    | RunProcessor. Because the lock TTL is NOT load-bearing for correctness,
    | we default it to a short value (60s) so stuck processes recover quickly.
    | If you need a longer value for your deployment, raise
    | CONDUCTOR_LOCK_TTL, but be aware the TTL is not what prevents
    | concurrent Atlas calls — the layered optimistic checks do.
    |
    */
    'locks' => [
        'store' => Env::get('CONDUCTOR_LOCK_STORE'),
        'prefix' => Env::get('CONDUCTOR_LOCK_PREFIX', 'conductor:run:'),
        'ttl' => (int) Env::get('CONDUCTOR_LOCK_TTL', 60),
    ],
];
