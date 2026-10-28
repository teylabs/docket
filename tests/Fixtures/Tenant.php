<?php

namespace Tey\Docket\Tests\Fixtures;

use Illuminate\Database\Eloquent\Model;
use Spatie\Multitenancy\Concerns\UsesMultitenancyConfig;
use Spatie\Multitenancy\Contracts\IsTenant;
use Spatie\Multitenancy\Models\Concerns\ImplementsTenant;

class Tenant extends Model implements IsTenant
{
    use ImplementsTenant, UsesMultitenancyConfig;

    public $timestamps = false;

    protected $guarded = [];
}
