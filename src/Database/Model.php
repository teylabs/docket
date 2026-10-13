<?php

namespace Tey\Docket\Database;

use Illuminate\Database\Eloquent\Model as Eloquent;

/** @internal */
abstract class Model extends Eloquent
{
    public function getConnectionName(): string|\UnitEnum
    {
        return $this->connection ?? app(Connection::class)->name();
    }
}
