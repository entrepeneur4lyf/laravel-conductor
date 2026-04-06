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
];
