<?php

namespace Tey\Docket\Exceptions;

use RuntimeException;

/** @api */
class RunFailed extends RuntimeException
{
    /** @param array<string, scalar|null> $params */
    public function __construct(public readonly string $failureCode, public readonly array $params = [])
    {
        parent::__construct($failureCode);
    }
}
