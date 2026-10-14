<?php

use Illuminate\Routing\RouteCollection;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;
use Tey\Docket\DocketServiceProvider;
use Tey\Docket\Run;
use Tey\Docket\Tests\Fixtures\ReadOnlyRunPolicy;

use function Pest\Laravel\getJson;
use function Pest\Laravel\postJson;

it('boots the provider and merges the schema and snapshot config', function () {
    expect(app()->isBooted())->toBeTrue()
        ->and(app()->getProvider(DocketServiceProvider::class))->toBeInstanceOf(DocketServiceProvider::class)
        ->and(config('docket'))->toBe([
            'connection' => null,
            'ttl' => 86400,
            'stale_after' => 3600,
            'poll_after_ms' => 2000,
            'max_depth' => 10,
            'routes' => ['enabled' => true, 'prefix' => 'docket', 'middleware' => ['web']],
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

it('registers only the six JSON routes with web middleware and cacheable controller actions', function () {
    $routes = collect(Route::getRoutes()->getRoutes())->filter(fn ($route) => str_starts_with($route->getName() ?? '', 'docket.'));
    expect($routes)->toHaveCount(6);
    $cacheable = new RouteCollection;
    foreach ($routes as $route) {
        expect($route->gatherMiddleware())->toContain('web');
        $route->prepareForSerialization();
        $cacheable->add($route);
    }
    expect($cacheable->compile()['attributes'])->toHaveKeys(['docket.show', 'docket.items', 'docket.logs', 'docket.children', 'docket.cancel', 'docket.retry']);
    getJson('/docket/runs/unknown/watch')->assertNotFound();
});

it('supports disabling routes or configuring their prefix and middleware without replacing an application policy', function (bool $enabled) {
    Route::setRoutes(new RouteCollection);
    config(['docket.routes' => ['enabled' => $enabled, 'prefix' => 'api/work', 'middleware' => ['api']]]);
    Gate::policy(Run::class, ReadOnlyRunPolicy::class);
    (new DocketServiceProvider(app()))->boot();
    $run = Run::create(['name' => 'configured']);
    getJson('/docket/runs/'.$run->token)->assertNotFound();
    if ($enabled) {
        getJson('/api/work/runs/'.$run->token)->assertOk()->assertJsonPath('links.poll', 'http://localhost/api/work/runs/'.$run->token);
        $middleware = Route::getRoutes()->getByName('docket.show')->gatherMiddleware();
        expect($middleware)->toContain('api')->and($middleware)->not->toContain('web');
    } else {
        getJson('/api/work/runs/'.$run->token)->assertNotFound();
    }
    expect(Gate::getPolicyFor(Run::class))->toBeInstanceOf(ReadOnlyRunPolicy::class);
})->with([true, false]);

it('keeps CSRF protection on the default web control routes', function () {
    app()->instance('env', 'production');
    try {
        postJson('/docket/runs/unknown/cancel')->assertStatus(419)->assertHeader('Cache-Control', 'no-store, private');
    } finally {
        app()->instance('env', 'testing');
    }
});
