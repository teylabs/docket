<?php

namespace Tey\Docket\Tests;

use Orchestra\Testbench\TestCase as Orchestra;
use Tey\Docket\DocketServiceProvider;

abstract class TestCase extends Orchestra
{
    private string $databasePath;

    protected function getEnvironmentSetUp($app): void
    {
        $path = tempnam(sys_get_temp_dir(), 'docket-');

        if ($path === false) {
            throw new \RuntimeException('Unable to create the test database.');
        }

        $this->databasePath = $path;
        $app['config']->set('app.key', 'base64:'.base64_encode(random_bytes(32)));
        $app['config']->set('database.default', 'testing');
        $app['config']->set('database.connections.testing', getenv('DOCKET_TEST_PGSQL_DATABASE') ? [
            // PostgreSQL is type-strict where SQLite is not (a varchar column never equals an integer), so CI also runs the suite on it.
            'driver' => 'pgsql',
            'host' => getenv('DOCKET_TEST_PGSQL_HOST') ?: '127.0.0.1',
            'port' => getenv('DOCKET_TEST_PGSQL_PORT') ?: '5432',
            'database' => getenv('DOCKET_TEST_PGSQL_DATABASE'),
            'username' => getenv('DOCKET_TEST_PGSQL_USERNAME') ?: 'postgres',
            'password' => getenv('DOCKET_TEST_PGSQL_PASSWORD') ?: '',
            // As behind a transaction pooler (PgBouncer, Neon, Laravel Cloud): bindings are inlined as typed literals, so a type mismatch is an error.
            'options' => [\PDO::ATTR_EMULATE_PREPARES => true],
        ] : [
            'driver' => 'sqlite',
            'database' => $path,
            'foreign_key_constraints' => true,
        ]);
    }

    protected function getPackageProviders($app): array
    {
        return [DocketServiceProvider::class];
    }

    protected function defineDatabaseMigrations(): void
    {
        if (getenv('DOCKET_TEST_PGSQL_DATABASE')) {
            // A PostgreSQL run shares one database, so each test starts from an empty schema, as each SQLite test starts from a new file.
            $this->app['db']->connection('testing')->unprepared('drop schema public cascade; create schema public');
        }
        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');
    }

    protected function tearDown(): void
    {
        $database = $this->app['db'];
        parent::tearDown();

        foreach ($database->getConnections() as $connection) {
            $connection->disconnect();
        }

        if (isset($this->databasePath) && is_file($this->databasePath)) {
            unlink($this->databasePath);
        }
    }
}
