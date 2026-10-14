<?php

namespace Tey\Docket\Facades;

use Illuminate\Support\Facades\Facade;

/**
 * @api
 *
 * @mixin \Tey\Docket\Docket
 */
class Docket extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return \Tey\Docket\Docket::class;
    }
}
