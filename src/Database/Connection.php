<?php

namespace Tey\Docket\Database;

use Illuminate\Database\DatabaseManager;

/** @internal */
class Connection
{
    public function __construct(private DatabaseManager $database) {}

    public function name(): string
    {
        $configured = config('docket.connection');

        if (is_string($configured)) {
            return $configured;
        }

        $default = $this->database->getDefaultConnection();
        $source = config('database.connections.'.$default.'.docket_source_connection');

        // Laravel temporarily makes the migration's connection the default.
        if (is_string($source) && $default === 'docket_'.$source) {
            return $default;
        }

        $name = 'docket_'.$default;

        if (! array_key_exists($name, $this->database->getConnections())) {
            config(['database.connections.'.$name => array_merge(
                config()->array('database.connections.'.$default),
                ['docket_source_connection' => $default],
            )]);
            $this->database->connection($name);
        }

        return $name;
    }
}
