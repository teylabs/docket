<?php

use Tey\Docket\Tests\TestCase;

uses(TestCase::class)->in('Feature');

/** Runs every Docket migration on the configured Docket connection, as a fresh install does. */
function migrateDocket(): void
{
    foreach (glob(__DIR__.'/../database/migrations/*.php') ?: [] as $path) {
        (require $path)->up();
    }
}

/** Reverses every Docket migration on the configured Docket connection, newest first. */
function rollbackDocket(): void
{
    foreach (array_reverse(glob(__DIR__.'/../database/migrations/*.php') ?: []) as $path) {
        (require $path)->down();
    }
}
