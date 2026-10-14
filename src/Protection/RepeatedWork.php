<?php

namespace Tey\Docket\Protection;

use RuntimeException;

/** @internal */
class RepeatedWork extends RuntimeException
{
    public function __construct(public readonly string $fingerprint, public readonly string $job, public readonly string $reference)
    {
        parent::__construct('Repeated work.');
    }
}
