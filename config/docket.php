<?php

return [
    'connection' => null,
    'ttl' => 86400,
    'stale_after' => 3600,
    'poll_after_ms' => 2000,
    'output_link_ttl' => 600,
    'stream' => [
        'seconds' => 60,
        'heartbeat_seconds' => 15,
        'check_ms' => 1000,
    ],
    'max_depth' => 10,
    'expired_link' => [
        'url' => null,
        'label' => 'Back',
    ],
    'routes' => [
        'enabled' => true,
        'prefix' => 'docket',
        'middleware' => ['web'],
    ],
];
