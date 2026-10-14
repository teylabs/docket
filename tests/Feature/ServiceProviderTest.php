<?php

use Tey\Docket\DocketServiceProvider;

it('boots the provider and merges the schema and snapshot config', function () {
    expect(app()->isBooted())->toBeTrue()
        ->and(app()->getProvider(DocketServiceProvider::class))->toBeInstanceOf(DocketServiceProvider::class)
        ->and(config('docket'))->toBe([
            'connection' => null,
            'ttl' => 86400,
            'stale_after' => 3600,
            'poll_after_ms' => 2000,
            'max_depth' => 10,
            'routes' => ['prefix' => 'docket'],
        ]);
});

it('publishes the migration and configuration under their documented tags', function () {
    $migrations = DocketServiceProvider::pathsToPublish(DocketServiceProvider::class, 'docket-migrations');
    $configuration = DocketServiceProvider::pathsToPublish(DocketServiceProvider::class, 'docket-config');

    expect($migrations)->toHaveCount(1)
        ->and(array_values($migrations))->toBe([database_path('migrations')])
        ->and($configuration)->toHaveCount(1)
        ->and(array_values($configuration))->toBe([config_path('docket.php')]);
});
