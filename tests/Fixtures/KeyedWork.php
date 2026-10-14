<?php

namespace Tey\Docket\Tests\Fixtures;

class KeyedWork extends RecordWork
{
    public function docketKey(): string
    {
        return $this->label;
    }
}
