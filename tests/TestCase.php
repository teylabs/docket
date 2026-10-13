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
        $app['config']->set('database.default', 'testing');
        $app['config']->set('database.connections.testing', [
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
