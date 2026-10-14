<?php

namespace Tey\Docket\Exceptions;

/** @api */
class LoopDetected extends RunFailed
{
    public function __construct(string $job, string $repeats)
    {
        parent::__construct('run.loop_detected', ['job' => $job, 'repeats' => $repeats]);
    }
}
