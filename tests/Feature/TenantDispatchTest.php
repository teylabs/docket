<?php

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Spatie\Multitenancy\Contracts\IsTenant;
use Spatie\Multitenancy\Multitenancy;
use Spatie\Multitenancy\MultitenancyServiceProvider;
use Tey\Docket\Facades\Docket;
use Tey\Docket\RunStatus;
use Tey\Docket\Tests\Fixtures\Tenant;
use Tey\Docket\Tests\Fixtures\TenantWork;

use function Pest\Laravel\artisan;

beforeEach(function () {
    config(['queue.default' => 'database', 'queue.connections.database.after_commit' => false, 'queue.failed.driver' => null]);

    Schema::create('jobs', function (Blueprint $table) {
        $table->bigIncrements('id');
        $table->string('queue')->index();
        $table->longText('payload');
        $table->unsignedTinyInteger('attempts');
        $table->unsignedInteger('reserved_at')->nullable();
        $table->unsignedInteger('available_at');
        $table->unsignedInteger('created_at');
    });
    Schema::create('work_results', fn (Blueprint $table) => $table->string('label'));
    Schema::create('tenants', fn (Blueprint $table) => $table->id());
});

afterEach(function () {
    if (app()->bound(IsTenant::class)) {
        Tenant::forgetCurrent();
    }
});

/** spatie/laravel-multitenancy as a host app configures it; a test that never calls this runs without the package booted. */
function useTenancy(bool $tenantAwareByDefault = true): void
{
    config([
        'multitenancy.tenant_model' => Tenant::class, 'multitenancy.switch_tenant_tasks' => [], 'multitenancy.tenant_finder' => null,
        'multitenancy.queues_are_tenant_aware_by_default' => $tenantAwareByDefault,
    ]);
    app()->register(MultitenancyServiceProvider::class);
    app(Multitenancy::class)->start();
}

/** @return list<string> */
function tenantResults(): array
{
    artisan('queue:work', ['--stop-when-empty' => true, '--sleep' => 0])->assertSuccessful();

    return DB::table('work_results')->pluck('label')->all();
}

it('carries the dispatching tenant through a commit that lands after the tenant block has ended', function () {
    useTenancy();
    $tenant = Tenant::query()->create();

    DB::beginTransaction();
    $tenant->execute(function (): void {
        Docket::dispatch(new TenantWork);
    });
    expect(Tenant::current())->toBeNull();
    DB::commit();

    expect(Queue::size())->toBe(1)
        ->and(Tenant::current())->toBeNull()
        ->and(tenantResults())->toBe(['tenant:'.$tenant->getKey()]);
});

it('restores the tenant that is current when the commit lands', function () {
    useTenancy();
    [$dispatching, $committing] = [Tenant::query()->create(), Tenant::query()->create()];

    DB::beginTransaction();
    $dispatching->execute(function (): void {
        Docket::dispatch(new TenantWork);
    });
    $committing->makeCurrent();
    DB::commit();

    expect(Tenant::current()?->getKey())->toBe($committing->getKey());
    Tenant::forgetCurrent();
    expect(tenantResults())->toBe(['tenant:'.$dispatching->getKey()]);
});

it('dispatches with no tenant as before', function () {
    useTenancy(tenantAwareByDefault: false);

    DB::beginTransaction();
    Docket::dispatch(new TenantWork);
    DB::commit();

    expect(Tenant::current())->toBeNull()->and(tenantResults())->toBe(['tenant:none']);
});

it('enqueues nothing when a nested transaction rolls back inside a tenant', function () {
    useTenancy();
    $tenant = Tenant::query()->create();

    DB::beginTransaction();
    DB::beginTransaction();
    $ticket = $tenant->execute(fn () => Docket::dispatch(new TenantWork));
    DB::rollBack();
    DB::commit();

    expect(Queue::size())->toBe(0)
        ->and($ticket->run()->status)->toBe(RunStatus::Failed)
        ->and($ticket->run()->error['code'])->toBe('run.rolled_back')
        ->and(Tenant::current())->toBeNull();
});

it('dispatches as before when multitenancy is not configured', function () {
    expect(app()->bound(IsTenant::class))->toBeFalse();

    DB::beginTransaction();
    Docket::dispatch(new TenantWork);
    DB::commit();

    expect(Queue::size())->toBe(1)->and(tenantResults())->toBe(['tenant:none']);
});
