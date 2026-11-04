<?php

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Tey\Docket\Tests\Fixtures\User;
use Tey\Docket\Tests\TestCase;

uses(TestCase::class)->in('Feature');

/** Signs in a fixture user, creating the users table when the test has none: reuse is per initiator. */
function signInAs(int $id = 1): User
{
    if (! Schema::hasTable('users')) {
        Schema::create('users', function (Blueprint $table) {
            $table->id();
            $table->timestamps();
        });
    }

    $user = User::query()->find($id) ?? User::query()->forceCreate(['id' => $id]);
    \Pest\Laravel\actingAs($user);

    return $user;
}

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
