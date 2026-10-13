<?php

namespace Tey\Docket\Tests;

use Orchestra\Testbench\TestCase as Orchestra;
use Tey\Docket\DocketServiceProvider;

abstract class TestCase extends Orchestra
{
    protected function getPackageProviders($app): array
    {
        return [DocketServiceProvider::class];
    }
}
