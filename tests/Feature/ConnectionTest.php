<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Migrations\Migrator;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Schema\Grammars\SQLiteGrammar;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tey\Docket\Database\Connection;

it('reuses its generated connection while Laravel temporarily makes it the default', function () {
    $original = DB::getDefaultConnection();
    $name = app(Connection::class)->name();
    $connection = DB::connection($name);

    try {
        DB::setDefaultConnection($name);
        expect(app(Connection::class)->name())->toBe($name)
            ->and(DB::connection(app(Connection::class)->name()))->toBe($connection);
        DB::purge($name);
        expect(app(Connection::class)->name())->toBe($name);
    } finally {
        DB::setDefaultConnection($original);
    }
});

it('rolls back migration writes on the same connection the migrator opened', function () {
    $original = DB::getDefaultConnection();
    $name = app(Connection::class)->name();
    $connection = DB::connection($name);
    // Exercise PostgreSQL's transactional migration path using isolated SQLite.
    $connection->setSchemaGrammar(new class($connection) extends SQLiteGrammar
    {
        public function supportsSchemaTransactions(): bool
        {
            return true;
        }
    });
    $migration = new class extends Migration
    {
        public function getConnection(): string
        {
            return app(Connection::class)->name();
        }

        public function up(): void
        {
            Schema::connection($this->getConnection())->create('docket_connection_probe', function (Blueprint $table): void {
                $table->id();
            });

            throw new RuntimeException('Stop after creating the probe.');
        }
    };

    $run = new ReflectionMethod(Migrator::class, 'runMigration');
    expect(fn () => $run->invoke(app('migrator'), $migration, 'up'))
        ->toThrow(RuntimeException::class, 'Stop after creating the probe.');
    expect(DB::getDefaultConnection())->toBe($original)
        ->and(Schema::connection($name)->hasTable('docket_connection_probe'))->toBeFalse();
});

it('does not mistake an application connection name for a generated connection', function () {
    $original = DB::getDefaultConnection();
    config(['database.connections.docket_application' => config('database.connections.'.$original)]);

    try {
        DB::setDefaultConnection('docket_application');
        expect(app(Connection::class)->name())->toBe('docket_docket_application');
    } finally {
        DB::setDefaultConnection($original);
    }
});

it('honours an explicitly configured connection during a default switch', function () {
    $original = DB::getDefaultConnection();
    config(['docket.connection' => $original]);

    try {
        DB::setDefaultConnection('unconfigured-temporary-default');
        expect(app(Connection::class)->name())->toBe($original);
    } finally {
        DB::setDefaultConnection($original);
    }
});
