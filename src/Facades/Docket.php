<?php

namespace Tey\Docket\Facades;

use Illuminate\Support\Facades\Facade;
use Tey\Docket\Testing\DocketFake;

/**
 * @api
 *
 * @mixin \Tey\Docket\Docket
 * @mixin DocketFake
 */
class Docket extends Facade
{
    public static function fake(): DocketFake
    {
        static::swap($fake = new DocketFake);

        return $fake;
    }

    protected static function getFacadeAccessor(): string
    {
        return \Tey\Docket\Docket::class;
    }
}
