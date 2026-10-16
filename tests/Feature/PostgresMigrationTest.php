<?php

use Illuminate\Database\PostgresConnection;
use Illuminate\Support\Facades\DB;
use Tey\Docket\Database\Connection;

it('creates the Docket primary key before every PostgreSQL self-reference', function (string $column) {
    $connection = new PostgresConnection(fn () => throw new RuntimeException('This test must not connect to a database.'));
    $connection->useDefaultSchemaGrammar();
    $manager = DB::getFacadeRoot();
    $originalDocket = app(Connection::class);
    $docket = Mockery::mock(Connection::class);
    $docket->shouldReceive('name')->once()->andReturn('docket-migration-test');
    app()->instance(Connection::class, $docket);
    DB::shouldReceive('connection')->once()->with('docket-migration-test')->andReturn($connection);
    $migration = require __DIR__.'/../../database/migrations/2026_10_13_000000_create_docket_tables.php';

    try {
        $queries = $connection->pretend(fn () => $migration->up());
    } finally {
        DB::swap($manager);
        app()->instance(Connection::class, $originalDocket);
    }
    $statements = array_column($queries, 'query');
    $primary = array_search('alter table "docket_runs" add primary key ("id")', $statements, true);
    expect($primary)->not->toBeFalse();

    $sql = 'alter table "docket_runs" add constraint "docket_runs_'.$column.'_foreign" foreign key ("'.$column.'") references "docket_runs" ("id") on delete set null';
    $foreign = array_search($sql, $statements, true);
    expect($foreign)->not->toBeFalse()
        ->and($primary)->toBeLessThan($foreign);
})->with(['parent_run_id', 'root_run_id', 'retry_of_run_id', 'linked_from_run_id']);
