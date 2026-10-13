<?php

namespace Tey\Docket;

use Illuminate\Support\ServiceProvider;

/** @api */
class DocketServiceProvider extends ServiceProvider
{
    /** @internal */
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/docket.php', 'docket');
    }
}
