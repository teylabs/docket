<?php

namespace Tey\Docket\Tests\Fixtures;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;
use Spatie\Multitenancy\Contracts\IsTenant;

class TenantWork implements ShouldQueue
{
    use Queueable;

    public function handle(): void
    {
        $tenant = app()->bound(IsTenant::class) ? Tenant::current() : null;
        $key = $tenant?->getKey();
        DB::table('work_results')->insert(['label' => 'tenant:'.(is_int($key) ? (string) $key : 'none')]);
    }
}
