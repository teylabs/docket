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

        $name = 'docket_'.$this->database->getDefaultConnection();

        if (! array_key_exists($name, $this->database->getConnections())) {
            config(['database.connections.'.$name => config('database.connections.'.$this->database->getDefaultConnection())]);
            $this->database->connection($name);
        }

        return $name;
    }
}
