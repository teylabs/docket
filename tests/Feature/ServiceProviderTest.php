<?php

use Tey\Docket\DocketServiceProvider;

it('boots the provider and merges the empty config', function () {
    expect(app()->isBooted())->toBeTrue()
        ->and(app()->getProvider(DocketServiceProvider::class))->toBeInstanceOf(DocketServiceProvider::class)
        ->and(config('docket'))->toBe([]);
});
